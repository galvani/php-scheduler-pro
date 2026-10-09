<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\SchedulerInterface;
use Scheduling\Tests\Run\CollectingLogger;
use Scheduling\Tests\Task\Tasks;

final class DatabaseTasksTest extends LaravelTestCase
{
    /**
     * @throws BindingResolutionException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskConflictException
     */
    public function testTaskSavedThroughTheSchedulerRegistersOnTheNextScheduleResolution(): void
    {
        $this->enableDatabase();
        $id = $this->newTaskId();
        $this->writeTasks("tasks: {}\n");
        self::assertSame([], $this->laravel()->make(Schedule::class)->events());

        $this->laravel()->make(SchedulerInterface::class)->schedule(Tasks::make($id));
        $this->laravel()->forgetInstance(Schedule::class);
        $events = $this->laravel()->make(Schedule::class)->events();

        self::assertSame(["scheduler:task:{$id}"], array_map(static fn (Event $e): string => $e->mutexName(), $events));
        self::assertSame('0 6 * * *', $events[0]->expression);
    }

    /**
     * @throws BindingResolutionException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskConflictException
     */
    public function testYamlTaskCannotBeOverwrittenThroughTheDatabase(): void
    {
        $this->enableDatabase();
        $id = $this->newTaskId();
        $this->writeTasks(<<<YAML
            tasks:
              {$id}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($id)}"]
                max_runtime: 120
            YAML);

        $this->expectException(TaskNotWritableException::class);

        $this->laravel()->make(SchedulerInterface::class)->schedule(Tasks::make($id));
    }

    /** @throws BindingResolutionException */
    public function testUnreachableDatabaseIsLoggedAndYamlTasksStillRegister(): void
    {
        $id = $this->newTaskId();
        $this->writeTasks(<<<YAML
            tasks:
              {$id}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($id)}"]
                max_runtime: 120
            YAML);
        config([
            'database.connections.unreachable' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => ''],
            'scheduling.database' => ['enabled' => true, 'connection' => 'unreachable', 'table' => 'scheduler_tasks'],
        ]);
        $logger = new CollectingLogger();
        $this->laravel()->instance(LoggerInterface::class, $logger);

        $events = $this->laravel()->make(Schedule::class)->events();

        self::assertSame(["scheduler:task:{$id}"], array_map(static fn (Event $e): string => $e->mutexName(), $events));
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Database task source unreachable; running the YAML tasks only', $logger->records[0]['message']);
    }

    /** @throws BindingResolutionException */
    private function enableDatabase(): void
    {
        config(['scheduling.database' => ['enabled' => true, 'connection' => null, 'table' => 'scheduler_tasks']]);
        self::assertSame(0, $this->laravel()->make(Kernel::class)->call('migrate', [
            '--path' => realpath(__DIR__.'/../../../database/migrations'),
            '--realpath' => true,
        ]));
    }
}
