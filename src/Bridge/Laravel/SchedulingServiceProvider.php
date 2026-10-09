<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeZone;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use PDOException;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Scheduling\Lock\MutexInterface;
use Scheduling\Lock\RedisMutex;
use Scheduling\Run\RecordingRunListener;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Scheduler;
use Scheduling\SchedulerInterface;
use Scheduling\Task\CompositeTaskSource;
use Scheduling\Task\PdoTaskRepository;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\TaskSourceInterface;
use Scheduling\Task\YamlTaskSource;

final class SchedulingServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../../../config/scheduling.php';
    private const string REDIS = 'scheduling.redis';
    private const string MIGRATION = '2026_10_09_000000_create_scheduler_tasks_table.php';

    /**
     * Registers any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'scheduling');

        $this->app->singleton(self::REDIS, static fn (Application $app): Redis => self::redis($app));
        $this->app->singleton(MutexInterface::class, static fn (Application $app): MutexInterface => new RedisMutex(self::client($app)));
        $this->app->singleton(RunRepositoryInterface::class, static fn (Application $app): RunRepositoryInterface => new RedisRunRepository(self::client($app)));
        $this->app->singleton(TaskDefinitionMapper::class, static fn (Application $app): TaskDefinitionMapper => new TaskDefinitionMapper(
            new DateTimeZone($app->make(Config::class)->string('app.timezone')),
            (string) $app->environment(),
        ));
        $this->app->singleton(PdoTaskRepository::class, static function (Application $app): PdoTaskRepository {
            $config = $app->make(Config::class);
            $connection = $config->get('scheduling.database.connection');
            $pdo = $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null)->getPdo();

            return new PdoTaskRepository($pdo, $app->make(TaskDefinitionMapper::class), $config->string('scheduling.database.table'));
        });
        $this->app->singleton(TaskSourceInterface::class, static function (Application $app): TaskSourceInterface {
            $yaml = new YamlTaskSource($app->make(Config::class)->string('scheduling.tasks_file'), $app->make(TaskDefinitionMapper::class));
            $database = self::database($app);

            return null !== $database ? new CompositeTaskSource([$yaml, $database]) : $yaml;
        });
        $this->app->singleton(SchedulerInterface::class, static fn (Application $app): SchedulerInterface => new Scheduler(
            $app->make(TaskSourceInterface::class),
            self::database($app),
        ));
        $this->app->singleton(TaskIndex::class);
        $this->app->singleton(RunListenerInterface::class, static fn (Application $app): RunListenerInterface => new RecordingRunListener(
            $app->make(RunRepositoryInterface::class),
            $app->make(SchedulerInterface::class)->tasks()->tasks,
            $app->make(Config::class)->string('scheduling.host'),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(EventMutex::class, static fn (Application $app): EventMutex => new LaravelEventMutex(
            $app->make(MutexInterface::class),
            $app->make(TaskIndex::class),
            $app->make(CacheEventMutex::class),
            $app->make(RunListenerInterface::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(SchedulingMutex::class, static fn (Application $app): SchedulingMutex => new LaravelSchedulingMutex(
            $app->make(MutexInterface::class),
            $app->make(TaskIndex::class),
            $app->make(RunListenerInterface::class),
            $app->make(LoggerInterface::class),
        ));
    }

    /** @throws BindingResolutionException */
    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('scheduling.php')], 'scheduling-config');
        $this->publishes([
            __DIR__.'/../../../database/migrations/'.self::MIGRATION => database_path('migrations/'.self::MIGRATION),
        ], 'scheduling-migrations');

        $this->app->make(Dispatcher::class)->listen(ScheduledTaskSkipped::class, SkippedSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExportCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule, Application $app): void {
            $engine = new LaravelEngine($schedule, $app->make(RunListenerInterface::class), $app->make(TaskIndex::class));
            $logger = $app->make(LoggerInterface::class);
            foreach ($app->make(SchedulerInterface::class)->registerWith($engine)->problems as $problem) {
                $logger->warning($problem->reason, ['task_id' => $problem->taskId, 'source' => $problem->source]);
            }
        });
    }

    /**
     * The scheduler's own phpredis connection, opened from the Laravel Redis connection named in the config
     * (host, port, credentials, database, TLS) but without the application's key prefix: every engine and every
     * server must use the same lock keys, and Laravel would otherwise put `<app>-database-` in front of them.
     * It is a connection of its own, not bound as `Redis`, so the application's own Redis client is untouched.
     * When Redis is down at connect time it is logged and an unconnected client is returned, so every lock call
     * fails as LockUnavailableException and the run is skipped, as on any other Redis failure.
     *
     * @throws BindingResolutionException
     */
    private static function redis(Application $app): Redis
    {
        $name = $app->make(Config::class)->string('scheduling.redis');
        try {
            $client = $app->make(RedisManager::class)->resolve($name)->client();
            if (!$client instanceof Redis) {
                throw new InvalidArgumentException("Redis connection \"{$name}\" must use the phpredis client, got ".get_debug_type($client));
            }
            $client->setOption(Redis::OPT_PREFIX, '');

            return $client;
        } catch (RedisException $e) {
            $app->make(LoggerInterface::class)->warning('Scheduler Redis unreachable; runs that need a lock are skipped', ['connection' => $name, 'exception' => $e]);

            return new Redis();
        }
    }

    /**
     * The one scheduler connection, shared by the mutex and the run record.
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

    /**
     * The database task repository, or null when the database source is off or unreachable. An unreachable
     * database is logged and the YAML tasks still run, the same rule as for a bad row.
     *
     * @throws BindingResolutionException
     */
    private static function database(Application $app): ?PdoTaskRepository
    {
        if (!$app->make(Config::class)->boolean('scheduling.database.enabled')) {
            return null;
        }
        try {
            return $app->make(PdoTaskRepository::class);
        } catch (PDOException $e) { // @phpstan-ignore catch.neverThrown (Laravel connects on resolve and throws PDOException without declaring it)
            $app->make(LoggerInterface::class)->warning('Database task source unreachable; running the YAML tasks only', ['exception' => $e]);

            return null;
        }
    }
}
