<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Closure;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Scheduling\Bridge\Laravel\LaravelEventMutex;
use Scheduling\Bridge\Laravel\LaravelSchedulingMutex;
use Scheduling\Bridge\Laravel\SchedulingServiceProvider;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Lock\RedisMutex;
use Scheduling\Task\TaskId;
use Scheduling\Tests\CollectingLogger;
use stdClass;

final class SchedulingServiceProviderTest extends LaravelTestCase
{
    /** @throws JsonException */
    public function testProviderIsListedForAutoDiscoveryAndLoaded(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['extra']);
        self::assertIsArray($composer['extra']['laravel']);
        self::assertSame([SchedulingServiceProvider::class], $composer['extra']['laravel']['providers']);
        self::assertTrue($this->laravel()->providerIsLoaded(SchedulingServiceProvider::class));
    }

    public function testConfigIsMergedUnderTheSchedulingKey(): void
    {
        self::assertSame('scheduler', config('scheduling.redis'));
        self::assertIsString(config('scheduling.tasks_file'));
    }

    public function testConfigIsPublishable(): void
    {
        $config = ServiceProvider::pathsToPublish(SchedulingServiceProvider::class, 'scheduling-config');

        self::assertSame([realpath(__DIR__.'/../../../config/scheduling.php')], array_map(realpath(...), array_keys($config)));
        self::assertSame([config_path('scheduling.php')], array_values($config));
    }

    /** @throws BindingResolutionException */
    public function testCollaboratorsResolveToTheRedisImplementations(): void
    {
        self::assertInstanceOf(RedisMutex::class, $this->laravel()->make(MutexInterface::class));
        self::assertInstanceOf(LaravelEventMutex::class, $this->laravel()->make(EventMutex::class));
        self::assertInstanceOf(LaravelSchedulingMutex::class, $this->laravel()->make(SchedulingMutex::class));
    }

    /** @throws BindingResolutionException */
    public function testTheSchedulerRedisClientIsNotBoundForTheApplication(): void
    {
        self::assertFalse($this->laravel()->bound(Redis::class));
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testLocksIgnoreTheApplicationsRedisKeyPrefix(): void
    {
        config(['database.redis.options.prefix' => 'app-database-']);
        $id = new TaskId($this->newTaskId());

        $lock = $this->laravel()->make(MutexInterface::class)->acquire(LockKey::forTask($id), 60);

        self::assertNotNull($lock);
        self::assertSame(1, $this->redis->exists("scheduler:task:{$id->value}"));
        self::assertSame(0, $this->redis->exists("app-database-scheduler:task:{$id->value}"));
        $lock->release();
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     */
    public function testUnreachableRedisIsLoggedAndLockingThenFailsAsUnavailable(): void
    {
        config(['database.redis.default.port' => 1]);
        $logger = new CollectingLogger();
        $this->laravel()->instance(LoggerInterface::class, $logger);
        $mutex = $this->laravel()->make(MutexInterface::class);

        self::assertSame('Scheduler Redis unreachable; runs that need a lock are skipped', $logger->records[0]['message']);
        $this->expectException(LockUnavailableException::class);
        $mutex->acquire(LockKey::forTask(new TaskId($this->newTaskId())), 60);
    }

    /** @throws BindingResolutionException */
    public function testTheSchedulerSharesTheLaravelManagedConnection(): void
    {
        self::assertSame(
            $this->laravel()->make(RedisManager::class)->connection('scheduler')->client(),
            $this->laravel()->make('scheduling.redis'),
        );
        self::assertSame('', config('database.redis.scheduler.prefix'));
        self::assertSame(config('database.redis.default.host'), config('database.redis.scheduler.host'));
    }

    /** @throws BindingResolutionException */
    public function testAConnectionWithAKeyPrefixIsRefused(): void
    {
        config([
            'database.redis.prefixed' => [...(array) config('database.redis.default'), 'prefix' => 'app-'],
            'scheduling.redis' => 'prefixed',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis connection "prefixed" adds the key prefix "app-" to every key');

        $this->laravel()->make(MutexInterface::class);
    }

    /** @throws BindingResolutionException */
    public function testClientThatIsNotPhpRedisFailsClearly(): void
    {
        $this->laravel()->singleton('redis', static fn (Application $app): RedisManager => new class($app, 'predis', []) extends RedisManager {
            public function connection($name = null): Connection
            {
                return new class extends Connection {
                    /** @param array<string> $channels */
                    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

                    public function client(): stdClass
                    {
                        return new stdClass();
                    }
                };
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis connection "scheduler" must use the phpredis client, got stdClass');

        $this->laravel()->make(MutexInterface::class);
    }
}
