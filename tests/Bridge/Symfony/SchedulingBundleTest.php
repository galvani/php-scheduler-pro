<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use Closure;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Scheduling\Bridge\Symfony\SchedulingBundle;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskId;
use Scheduling\Tests\RedisConnection;
use Scheduling\Tests\Run\CollectingLogger;
use Scheduling\Tests\Task\Tasks;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class SchedulingBundleTest extends TestCase
{
    private const array REQUIRED = ['tasks_file' => '/tasks.yaml', 'console_binary' => 'php /app/bin/console', 'redis' => 'redis://127.0.0.1:6379'];

    private Redis $redis;

    private string $directory;

    private string $id;

    private ?TestKernel $kernel = null;

    /** @throws RedisException */
    protected function setUp(): void
    {
        $this->redis = RedisConnection::open();
        $this->id = 'bundle-'.bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir().'/scheduling-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    /**
     * @throws IOException
     * @throws RedisException
     */
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
        $keys = $this->redis->keys("scheduler:*{$this->id}*");
        foreach (is_array($keys) ? $keys : [] as $key) {
            $this->redis->del($key);
        }
    }

    /** @throws InvalidConfigurationException */
    public function testConfigurationDefaults(): void
    {
        $config = $this->process(self::REQUIRED);

        self::assertSame([
            'tasks_file' => '/tasks.yaml',
            'console_binary' => 'php /app/bin/console',
            'redis' => 'redis://127.0.0.1:6379',
            'host' => null,
            'database' => ['enabled' => false, 'dsn' => null, 'user' => null, 'password' => null, 'table' => 'scheduler_tasks'],
        ], $config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process($config);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigs(): iterable
    {
        yield 'tasks_file missing' => [['console_binary' => 'php bin/console', 'redis' => 'redis://r']];
        yield 'console_binary missing' => [['tasks_file' => '/tasks.yaml', 'redis' => 'redis://r']];
        yield 'console_binary empty' => [['tasks_file' => '/tasks.yaml', 'console_binary' => '', 'redis' => 'redis://r']];
        yield 'redis missing' => [['tasks_file' => '/tasks.yaml', 'console_binary' => 'php bin/console']];
        yield 'redis empty' => [['tasks_file' => '/tasks.yaml', 'console_binary' => 'php bin/console', 'redis' => '']];
        yield 'old redis host and port' => [[...self::REQUIRED, 'redis' => ['host' => '127.0.0.1', 'port' => 6379]]];
        yield 'database enabled without dsn' => [[...self::REQUIRED, 'database' => ['enabled' => true]]];
    }

    /**
     * @throws Exception
     */
    public function testCrunzTasksIsTheOnlyPublicService(): void
    {
        $kernel = $this->boot([...$this->connection(), 'tasks_file' => $this->tasksFile(''), 'console_binary' => 'php bin/console']);

        self::assertSame([CrunzTasks::class], $kernel->visibility->publicIds);
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function testCrunzTasksReturnsTheScheduleHoldingTheYamlTasks(): void
    {
        $kernel = $this->boot([...$this->connection(), 'host' => 'test-host', 'console_binary' => 'php /app/bin/console',
            'tasks_file' => $this->tasksFile($this->row($this->id, 'app:hello'))]);

        $schedule = $this->crunzTasks($kernel)->schedule();

        self::assertCount(1, $schedule->events());
        self::assertSame('* * * * *', $schedule->events()[0]->getExpression());
        self::assertSame('php /app/bin/console app:hello', $schedule->events()[0]->buildCommand());
        foreach ($schedule->events()[0]->beforeCallbacks() as $callback) {
            $callback();
        }
        $recorded = $this->redis->lRange("scheduler:runs:{$this->id}", 0, -1);
        self::assertIsArray($recorded);
        self::assertCount(1, $recorded);
        self::assertIsString($recorded[0]);
        self::assertStringContainsString('"host":"test-host"', $recorded[0]);
        self::assertStringContainsString('"state":"started"', $recorded[0]);
    }

    /**
     * @throws Exception
     * @throws InvalidTaskException
     * @throws PDOException
     * @throws RedisException
     * @throws StorageException
     * @throws TaskNotWritableException
     */
    public function testTaskSavedThroughTheSchedulerReachesTheCrunzSchedule(): void
    {
        $kernel = $this->boot([
            ...$this->connection(),
            'console_binary' => 'php /app/bin/console',
            'tasks_file' => $this->tasksFile($this->row("{$this->id}-yaml", 'app:hello')),
            'database' => ['enabled' => true, 'dsn' => 'sqlite::memory:'],
        ], ['scheduling.pdo', SchedulerInterface::class]);
        $pdo = $kernel->getContainer()->get('scheduling.pdo');
        self::assertInstanceOf(PDO::class, $pdo);
        $pdo->exec('CREATE TABLE scheduler_tasks (id VARCHAR(100) PRIMARY KEY, definition TEXT NOT NULL)');
        $scheduler = $kernel->getContainer()->get(SchedulerInterface::class);
        self::assertInstanceOf(SchedulerInterface::class, $scheduler);

        $scheduler->schedule(Tasks::make("{$this->id}-db"));
        $schedule = $this->crunzTasks($kernel)->schedule();

        $commands = array_map(static fn ($event): string => $event->buildCommand(), $schedule->events());
        sort($commands);
        self::assertSame([
            'php /app/bin/console app:hello',
            "php /app/bin/console reports:send 'weekly' '--queue=high' '--force'",
        ], $commands);
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function testTaskProblemsAreLoggedAsWarningsThroughTheAppLogger(): void
    {
        $kernel = $this->boot([
            ...$this->connection(),
            'console_binary' => 'php /app/bin/console',
            'tasks_file' => $this->tasksFile($this->row($this->id, 'app:hello')."  {$this->id}-bad:\n    schedule: nope\n"),
        ], withLogger: true);

        $this->crunzTasks($kernel)->schedule();

        $logger = $kernel->getContainer()->get('logger');
        self::assertInstanceOf(CollectingLogger::class, $logger);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame("{$this->id}-bad", $logger->records[0]['context']['task_id']);
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function testDsnConnectionWritesTheRunRecordUnderTheUnprefixedKey(): void
    {
        $kernel = $this->boot([...$this->connection(), 'console_binary' => 'php /app/bin/console',
            'tasks_file' => $this->tasksFile($this->row($this->id, 'app:hello'))]);

        foreach ($this->crunzTasks($kernel)->schedule()->events()[0]->beforeCallbacks() as $callback) {
            $callback();
        }

        self::assertSame(1, $this->redis->lLen("scheduler:runs:{$this->id}"));
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function testServiceReferenceReusesTheAppsOwnRedis(): void
    {
        $kernel = $this->boot($this->withRedis('@app.redis'), ['scheduling.redis', 'app.redis'], appServices: function (ContainerBuilder $container): void {
            $container->register('app.redis', Redis::class)
                ->addMethodCall('connect', [$this->redis->getHost(), $this->redis->getPort()]);
        });

        self::assertSame($kernel->getContainer()->get('app.redis'), $kernel->getContainer()->get('scheduling.redis'));
    }

    /** @throws Exception */
    public function testServiceWithAKeyPrefixIsRefused(): void
    {
        $kernel = $this->boot($this->withRedis('@app.redis'), ['scheduling.redis'], appServices: function (ContainerBuilder $container): void {
            $container->register('app.redis', Redis::class)
                ->addMethodCall('connect', [$this->redis->getHost(), $this->redis->getPort()])
                ->addMethodCall('setOption', [Redis::OPT_PREFIX, 'app:']);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('service app.redis adds the key prefix "app:"');
        $this->expectExceptionMessage('without a key prefix');

        $kernel->getContainer()->get('scheduling.redis');
    }

    /**
     * @throws Exception
     * @throws LockUnavailableException
     */
    public function testDsnToADeadRedisLogsAWarningAndSkipsTheLock(): void
    {
        $kernel = $this->boot($this->withRedis('redis://127.0.0.1:1'), [MutexInterface::class], withLogger: true);
        $mutex = $kernel->getContainer()->get(MutexInterface::class);
        self::assertInstanceOf(MutexInterface::class, $mutex);

        try {
            $mutex->acquire(LockKey::forTask(new TaskId($this->id)), 30);
            self::fail('The lock must be unavailable');
        } catch (LockUnavailableException) {
            $this->assertUnreachableWarning($kernel);
        }
    }

    /**
     * @throws Exception
     * @throws LockUnavailableException
     */
    public function testLazyServiceToADeadRedisLogsAWarningAndSkipsTheLock(): void
    {
        $kernel = $this->boot($this->withRedis('@app.redis'), [MutexInterface::class], withLogger: true, appServices: static function (ContainerBuilder $container): void {
            $container->register('app.redis', Redis::class)
                ->setFactory([RedisAdapter::class, 'createConnection'])
                ->setArguments(['redis://127.0.0.1:1', ['lazy' => true]]);
        });
        $mutex = $kernel->getContainer()->get(MutexInterface::class);
        self::assertInstanceOf(MutexInterface::class, $mutex);

        try {
            $mutex->acquire(LockKey::forTask(new TaskId($this->id)), 30);
            self::fail('The lock must be unavailable');
        } catch (LockUnavailableException) {
            $this->assertUnreachableWarning($kernel);
        }
    }

    /** @throws ServiceCircularReferenceException */
    private function assertUnreachableWarning(TestKernel $kernel): void
    {
        $logger = $kernel->getContainer()->get('logger');
        self::assertInstanceOf(CollectingLogger::class, $logger);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Scheduler Redis unreachable; runs that need a lock are skipped', $logger->records[0]['message']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     *
     * @throws InvalidConfigurationException
     */
    private function process(array $config): array
    {
        $extension = new SchedulingBundle()->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return new Processor()->processConfiguration($configuration, [$config]);
    }

    /**
     * @param array<string, mixed> $scheduling
     * @param list<string>         $expose
     *
     * @throws Exception
     */
    private function boot(array $scheduling, array $expose = [], bool $withLogger = false, ?Closure $appServices = null): TestKernel
    {
        $this->kernel = new TestKernel($scheduling, $this->directory, $withLogger, [...$expose, CrunzTasks::class], $appServices);
        $this->kernel->boot();

        return $this->kernel;
    }

    /** @throws ServiceCircularReferenceException */
    private function crunzTasks(TestKernel $kernel): CrunzTasks
    {
        $tasks = $kernel->getContainer()->get(CrunzTasks::class);
        self::assertInstanceOf(CrunzTasks::class, $tasks);

        return $tasks;
    }

    /** @return array{redis: string} */
    private function connection(): array
    {
        return ['redis' => $this->dsn()];
    }

    private function dsn(): string
    {
        return 'redis://'.$this->redis->getHost().':'.$this->redis->getPort();
    }

    /**
     * @param array<string, mixed> $scheduling
     *
     * @return array<string, mixed>
     */
    private function withRedis(string $redis, array $scheduling = []): array
    {
        return ['redis' => $redis, 'console_binary' => 'php /app/bin/console', 'tasks_file' => $this->tasksFile(''), ...$scheduling];
    }

    private function tasksFile(string $rows): string
    {
        $path = "{$this->directory}/scheduling.yaml";
        file_put_contents($path, "tasks:\n{$rows}");

        return $path;
    }

    private function row(string $id, string $command): string
    {
        return "  {$id}:\n    schedule: '* * * * *'\n    command: {$command}\n    max_runtime: 30\n";
    }
}
