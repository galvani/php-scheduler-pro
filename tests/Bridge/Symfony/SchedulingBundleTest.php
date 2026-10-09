<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use Exception;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Scheduling\Bridge\Symfony\SchedulingBundle;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\SchedulerInterface;
use Scheduling\Tests\RedisConnection;
use Scheduling\Tests\Run\CollectingLogger;
use Scheduling\Tests\Task\Tasks;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class SchedulingBundleTest extends TestCase
{
    private const array REQUIRED = ['tasks_file' => '/tasks.yaml', 'console_binary' => 'php /app/bin/console'];

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
            'host' => null,
            'redis' => ['host' => '127.0.0.1', 'port' => 6379],
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
        yield 'tasks_file missing' => [['console_binary' => 'php bin/console']];
        yield 'console_binary missing' => [['tasks_file' => '/tasks.yaml']];
        yield 'console_binary empty' => [['tasks_file' => '/tasks.yaml', 'console_binary' => '']];
        yield 'database enabled without dsn' => [[...self::REQUIRED, 'database' => ['enabled' => true]]];
    }

    /**
     * @throws Exception
     */
    public function testCrunzTasksIsTheOnlyPublicService(): void
    {
        $kernel = $this->boot(['tasks_file' => $this->tasksFile(''), 'console_binary' => 'php bin/console']);

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
    private function boot(array $scheduling, array $expose = [], bool $withLogger = false): TestKernel
    {
        $this->kernel = new TestKernel($scheduling, $this->directory, $withLogger, [...$expose, CrunzTasks::class]);
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

    /** @return array{redis: array{host: string, port: int}} */
    private function connection(): array
    {
        return ['redis' => ['host' => (string) $this->redis->getHost(), 'port' => (int) $this->redis->getPort()]];
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
