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
use Redis;
use Scheduling\Bridge\Laravel\LaravelEventMutex;
use Scheduling\Bridge\Laravel\LaravelSchedulingMutex;
use Scheduling\Bridge\Laravel\SchedulingServiceProvider;
use Scheduling\Lock\MutexInterface;
use Scheduling\Lock\RedisMutex;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunRepositoryInterface;
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
        self::assertSame('default', config('scheduling.redis'));
        self::assertIsString(config('scheduling.host'));
        self::assertSame(
            ['enabled' => false, 'connection' => null, 'table' => 'scheduler_tasks'],
            config('scheduling.database'),
        );
    }

    public function testConfigAndMigrationArePublishable(): void
    {
        $config = ServiceProvider::pathsToPublish(SchedulingServiceProvider::class, 'scheduling-config');
        $migrations = ServiceProvider::pathsToPublish(SchedulingServiceProvider::class, 'scheduling-migrations');

        self::assertSame([realpath(__DIR__.'/../../../config/scheduling.php')], array_map(realpath(...), array_keys($config)));
        self::assertSame([config_path('scheduling.php')], array_values($config));
        self::assertCount(1, $migrations);
        self::assertFileExists(array_key_first($migrations));
        self::assertStringEndsWith('_create_scheduler_tasks_table.php', (string) array_key_first($migrations));
    }

    /** @throws BindingResolutionException */
    public function testCollaboratorsResolveToTheRedisImplementations(): void
    {
        self::assertInstanceOf(RedisMutex::class, $this->laravel()->make(MutexInterface::class));
        self::assertInstanceOf(RedisRunRepository::class, $this->laravel()->make(RunRepositoryInterface::class));
        self::assertInstanceOf(LaravelEventMutex::class, $this->laravel()->make(EventMutex::class));
        self::assertInstanceOf(LaravelSchedulingMutex::class, $this->laravel()->make(SchedulingMutex::class));
    }

    /** @throws BindingResolutionException */
    public function testTheSchedulerRedisClientIsNotBoundForTheApplication(): void
    {
        self::assertFalse($this->laravel()->bound(Redis::class));
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
        $this->expectExceptionMessage('Redis connection "default" must use the phpredis client, got stdClass');

        $this->laravel()->make(MutexInterface::class);
    }
}
