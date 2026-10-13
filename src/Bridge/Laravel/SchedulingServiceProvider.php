<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeZone;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Scheduling\Lock\MutexInterface;
use Scheduling\Lock\RedisMutex;
use Scheduling\Scheduler;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\TaskSourceInterface;
use Scheduling\Task\YamlTaskSource;

/**
 * Wires the package into a Laravel 12 or 13 application, discovered automatically. It binds the YAML task source,
 * the scheduler's own Redis connection and our mutex into both of Laravel's lock slots (EventMutex, SchedulingMutex),
 * and registers the tasks with Laravel's Schedule for the artisan commands that read it. Publishes
 * `config/scheduling.php`. An artisan command that does not read the schedule neither connects to Redis nor loads
 * the tasks.
 */
final class SchedulingServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../../../config/scheduling.php';
    private const string REDIS = 'scheduling.redis';
    private const string DEFAULT_CONNECTION = 'scheduler';

    /** The artisan commands that read the schedule's events. */
    private const array SCHEDULE_COMMANDS = [
        'schedule:run', 'schedule:list', 'schedule:test', 'schedule:finish', 'schedule:clear-cache',
    ];

    private bool $scheduleCommandStarted = false;

    private ?Schedule $registeredWith = null;

    /**
     * Registers any application services.
     *
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'scheduling');
        $this->app->beforeResolving('redis', static fn (string $abstract, array $parameters, Application $app) => self::addSchedulerConnection($app));

        $this->app->singleton(self::REDIS, static fn (Application $app): Redis => self::redis($app));
        $this->app->singleton(MutexInterface::class, static fn (Application $app): MutexInterface => new RedisMutex(self::client($app)));
        $this->app->singleton(TaskDefinitionMapper::class, static fn (Application $app): TaskDefinitionMapper => new TaskDefinitionMapper(
            new DateTimeZone($app->make(Config::class)->string('app.timezone')),
        ));
        $this->app->singleton(TaskSourceInterface::class, static fn (Application $app): TaskSourceInterface => new YamlTaskSource(
            $app->make(Config::class)->string('scheduling.tasks_file'),
            $app->make(TaskDefinitionMapper::class),
        ));
        $this->app->singleton(SchedulerInterface::class, static fn (Application $app): SchedulerInterface => new Scheduler($app->make(TaskSourceInterface::class)));
        $this->app->singleton(TaskIndex::class);
        $this->app->singleton(EventMutex::class, static fn (Application $app): EventMutex => new LaravelEventMutex(
            self::lazyMutex($app),
            $app->make(TaskIndex::class),
            $app->make(CacheEventMutex::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(SchedulingMutex::class, static fn (Application $app): SchedulingMutex => new LaravelSchedulingMutex(
            self::lazyMutex($app),
            $app->make(TaskIndex::class),
            $app->make(LoggerInterface::class),
        ));
    }

    /**
     * Publishes the config and registers the tasks with Laravel's Schedule.
     *
     * Laravel builds its Schedule on every artisan command, `migrate` and `config:clear` included, so in an
     * artisan process the tasks are registered only for a command that reads the schedule (SCHEDULE_COMMANDS),
     * once Laravel announces it with CommandStarting. Laravel announces no command under unit tests, and an HTTP
     * request or a queue job runs no artisan command: there the tasks are registered as soon as the Schedule is
     * built.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('scheduling.php')], 'scheduling-config');

        $waitForCommand = $this->app->runningInConsole() && !$this->app->runningUnitTests();
        if ($waitForCommand) {
            $this->app->make(Dispatcher::class)->listen(CommandStarting::class, function (CommandStarting $event): void {
                if (!in_array($event->command, self::SCHEDULE_COMMANDS, true)) {
                    return;
                }
                $this->scheduleCommandStarted = true;
                if ($this->app->resolved(Schedule::class)) {
                    $this->registerTasks($this->app->make(Schedule::class));
                }
            });
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($waitForCommand): void {
            if (!$waitForCommand || $this->scheduleCommandStarted) {
                $this->registerTasks($schedule);
            }
        });
    }

    /**
     * Registers the tasks with a Schedule, once per Schedule, and logs a warning for every task that was refused.
     *
     * @throws BindingResolutionException
     */
    private function registerTasks(Schedule $schedule): void
    {
        if ($this->registeredWith === $schedule) {
            return;
        }
        $this->registeredWith = $schedule;
        // Resolved here so a misconfigured Redis connection fails the command as it starts, not at the first due task.
        $this->app->make(MutexInterface::class);
        $engine = new LaravelEngine($schedule, $this->app->make(TaskIndex::class));
        $logger = $this->app->make(LoggerInterface::class);
        foreach ($this->app->make(SchedulerInterface::class)->registerWith($engine)->problems as $problem) {
            $logger->warning($problem->reason, ['task_id' => $problem->taskId, 'source' => $problem->source]);
        }
    }

    /**
     * The mutex for Laravel's lock slots, resolved from the container on the first lock call.
     */
    private static function lazyMutex(Application $app): LazyMutex
    {
        return new LazyMutex(static fn (): MutexInterface => $app->make(MutexInterface::class));
    }

    /**
     * Adds the `scheduler` Redis connection, a copy of the app's `default` connection without its key prefix, unless
     * the app defines one. It runs just before Laravel builds its RedisManager, because the manager reads its
     * connections once, when it is built, and anything in the app may build it before the scheduler needs it.
     *
     * @throws BindingResolutionException
     */
    private static function addSchedulerConnection(Application $app): void
    {
        $config = $app->make(Config::class);
        $default = $config->get('database.redis.default');
        if (!$config->has('database.redis.'.self::DEFAULT_CONNECTION) && is_array($default)) {
            $config->set('database.redis.'.self::DEFAULT_CONNECTION, [...$default, 'prefix' => '']);
        }
    }

    /**
     * The scheduler's Redis client: the Laravel-managed client of the connection named in the config, shared with
     * anything else in the app that uses that connection.
     *
     * The default name, `scheduler`, is the connection addSchedulerConnection() derives from the app's `default`
     * one: the same host, credentials and database, without the key prefix Laravel puts on every connection. A
     * prefix would be added to every lock key, and servers on another engine or another app would no longer share
     * the locks, so a connection with a prefix is refused. When Redis is down at connect time it is logged and an
     * unconnected client is returned, so every lock call fails as LockUnavailableException and the run is skipped,
     * as on any other Redis failure.
     *
     * @throws BindingResolutionException
     */
    private static function redis(Application $app): Redis
    {
        $name = $app->make(Config::class)->string('scheduling.redis');
        try {
            $client = $app->make(RedisManager::class)->connection($name)->client();
            if (!$client instanceof Redis) {
                throw new InvalidArgumentException("Redis connection \"{$name}\" must use the phpredis client, got ".get_debug_type($client));
            }
            $prefix = $client->getOption(Redis::OPT_PREFIX);
        } catch (RedisException $e) {
            $app->make(LoggerInterface::class)->warning(
                'Scheduler Redis unreachable; runs that need a lock are skipped',
                ['connection' => $name, 'exception' => $e],
            );

            return new Redis();
        }
        if (is_string($prefix) && '' !== $prefix) {
            throw new InvalidArgumentException("Redis connection \"{$name}\" adds the key prefix \"{$prefix}\" to every key. The scheduler needs a ".'connection without a prefix, so every server and engine shares the lock keys: give it its own '."connection with 'prefix' => '' in config/database.php and set SCHEDULING_REDIS_CONNECTION.");
        }

        return $client;
    }

    /**
     * The scheduler's connection, as a phpredis client.
     *
     * @throws BindingResolutionException
     */
    private static function client(Application $app): Redis
    {
        $client = $app->make(self::REDIS);
        if (!$client instanceof Redis) {
            throw new LogicException('The scheduling.redis service must be a phpredis client');
        }

        return $client;
    }
}
