<?php declare(strict_types=1);

namespace Scheduling\Tests;

use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Scheduler;
use Scheduling\Task\CompositeTaskSource;
use Scheduling\Task\Condition\Environments;
use Scheduling\Task\PdoTaskRepository;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;
use Scheduling\Task\TaskSet;
use Scheduling\Task\TaskSourceInterface;
use Scheduling\Tests\Task\InMemoryTaskRepository;
use Scheduling\Tests\Task\Tasks;

final class SchedulerTest extends TestCase
{
    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testWritesGoThroughToTheRuntimeRepository(): void
    {
        $runtime = new InMemoryTaskRepository();
        $scheduler = $this->scheduler(new InMemoryTaskRepository(), $runtime);

        $scheduler->schedule(Tasks::make('a'));
        self::assertSame(['a'], array_keys($runtime->load()->tasks));

        $scheduler->disable(new TaskId('a'));
        self::assertFalse($runtime->load()->tasks['a']->enabled);

        $scheduler->remove(new TaskId('a'));
        self::assertSame([], $runtime->load()->tasks);
    }

    /**
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskConflictException
     */
    public function testRefusesToScheduleAnIdOwnedByAReadOnlySource(): void
    {
        $readOnly = new InMemoryTaskRepository(['fixed' => Tasks::make('fixed')]);
        $scheduler = $this->scheduler($readOnly, new InMemoryTaskRepository());

        $this->expectException(TaskNotWritableException::class);

        $scheduler->schedule(Tasks::make('fixed'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskNotFoundException
     */
    public function testRefusesToDisableAnIdOwnedByAReadOnlySource(): void
    {
        $scheduler = $this->scheduler(new InMemoryTaskRepository(['fixed' => Tasks::make('fixed')]), new InMemoryTaskRepository());

        $this->expectException(TaskNotWritableException::class);

        $scheduler->disable(new TaskId('fixed'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskNotFoundException
     */
    public function testRefusesToRemoveAnIdOwnedByAReadOnlySource(): void
    {
        $scheduler = $this->scheduler(new InMemoryTaskRepository(['fixed' => Tasks::make('fixed')]), new InMemoryTaskRepository());

        $this->expectException(TaskNotWritableException::class);

        $scheduler->remove(new TaskId('fixed'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotWritableException
     * @throws TaskNotFoundException
     */
    public function testAnIdOwnedByTheRuntimeRepositoryIsWritable(): void
    {
        $runtime = new InMemoryTaskRepository(['mine' => Tasks::make('mine')]);
        $scheduler = new Scheduler($runtime, $runtime);

        $scheduler->remove(new TaskId('mine'));

        self::assertSame([], $runtime->load()->tasks);
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotWritableException
     */
    public function testSchedulingAnIdThatIsAlreadyScheduledIsAConflict(): void
    {
        $runtime = new InMemoryTaskRepository(['mine' => Tasks::make('mine')]);

        $this->expectException(TaskConflictException::class);

        new Scheduler($runtime, $runtime)->schedule(Tasks::make('mine', enabled: false));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotFoundException
     * @throws TaskNotWritableException
     */
    public function testRemovingAnUnknownIdIsNotFound(): void
    {
        $runtime = new InMemoryTaskRepository();

        $this->expectException(TaskNotFoundException::class);

        new Scheduler($runtime, $runtime)->remove(new TaskId('typo'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotFoundException
     */
    public function testWithoutARuntimeRepositoryEveryWriteIsRefused(): void
    {
        $scheduler = new Scheduler(new InMemoryTaskRepository());

        foreach ([
            static fn () => $scheduler->schedule(Tasks::make('a')),
            static fn () => $scheduler->disable(new TaskId('a')),
            static fn () => $scheduler->remove(new TaskId('a')),
        ] as $write) {
            try {
                $write();
                self::fail('The write was not refused');
            } catch (TaskNotWritableException $e) {
                self::assertSame('No writable task source is configured', $e->getMessage());
            }
        }
    }

    /**
     * A read-only source that cannot be read may hold the id; scheduling it in the database would hide the
     * read-only copy as a duplicate once the source is readable again.
     *
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testRefusesWritesWhileAReadOnlySourceCannotBeRead(): void
    {
        $runtime = new InMemoryTaskRepository();
        $scheduler = $this->scheduler($this->source(new TaskProblem(null, 'yaml', 'Duplicate key "x"')), $runtime);

        foreach ([
            static fn () => $scheduler->schedule(Tasks::make('x')),
            static fn () => $scheduler->disable(new TaskId('x')),
            static fn () => $scheduler->remove(new TaskId('x')),
        ] as $write) {
            try {
                $write();
                self::fail('The write was not refused');
            } catch (TaskNotWritableException $e) {
                self::assertStringContainsString('Duplicate key "x"', $e->getMessage());
            }
        }
        self::assertSame([], $runtime->load()->tasks);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotFoundException
     * @throws TaskNotWritableException
     */
    public function testAnIdOwnedByTheRuntimeRepositoryStaysWritableWhileAReadOnlySourceCannotBeRead(): void
    {
        $runtime = new InMemoryTaskRepository(['mine' => Tasks::make('mine')]);

        $this->scheduler($this->source(new TaskProblem(null, 'yaml', 'unreadable')), $runtime)->remove(new TaskId('mine'));

        self::assertSame([], $runtime->load()->tasks);
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotWritableException
     */
    public function testRefusesAnIdThatAReadOnlySourceReportsAsInvalid(): void
    {
        $scheduler = $this->scheduler($this->source(new TaskProblem('x', 'yaml', 'Invalid cron expression')), new InMemoryTaskRepository());

        $this->expectException(TaskNotWritableException::class);

        $scheduler->schedule(Tasks::make('x'));
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotWritableException
     */
    public function testAProblemWithAnotherIdDoesNotRefuseTheWrite(): void
    {
        $runtime = new InMemoryTaskRepository();

        $this->scheduler($this->source(new TaskProblem('y', 'yaml', 'Invalid cron expression')), $runtime)->schedule(Tasks::make('x'));

        self::assertSame(['x'], array_keys($runtime->load()->tasks));
    }

    /**
     * The runtime repository's own read failure is not a read-only source hiding the id: the write goes to the
     * repository, which reports its own failure.
     *
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotWritableException
     */
    public function testAnUnreadableRuntimeRepositoryFailsAsStorageNotAsNotWritable(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $runtime = new PdoTaskRepository($pdo, new TaskDefinitionMapper(new DateTimeZone('UTC'), 'production'));
        $scheduler = new Scheduler(new CompositeTaskSource([new InMemoryTaskRepository(), $runtime]), $runtime);

        $this->expectException(StorageException::class);

        $scheduler->schedule(Tasks::make('x'));
    }

    public function testRegistersTheLoadedTasksWithTheEngine(): void
    {
        $engine = new FakeEngine();
        $set = new Scheduler(new InMemoryTaskRepository(['a' => Tasks::make('a'), 'b' => Tasks::make('b')]))->registerWith($engine);

        self::assertSame(['a', 'b'], array_keys($engine->registered));
        self::assertSame(['a', 'b'], array_keys($set->tasks));
        self::assertSame([], $set->problems);
    }

    public function testRefusesConditionsTheEngineCannotEvaluate(): void
    {
        $engine = new FakeEngine(Fulfilment::Unavailable);
        $conditional = Tasks::make('conditional', [new Environments(['production'], 'production')]);
        $set = new Scheduler(new InMemoryTaskRepository(['conditional' => $conditional, 'plain' => Tasks::make('plain')]))->registerWith($engine);

        self::assertSame(['plain'], array_keys($engine->registered));
        self::assertSame(['plain'], array_keys($set->tasks));
        self::assertCount(1, $set->problems);
        self::assertSame('conditional', $set->problems[0]->taskId);
        self::assertSame('fake', $set->problems[0]->source);
    }

    public function testKeepsConditionsWhenTheEngineSupportsThem(): void
    {
        $engine = new FakeEngine(Fulfilment::Polyfilled);
        $conditional = Tasks::make('conditional', [new Environments(['production'], 'production')]);
        $set = new Scheduler(new InMemoryTaskRepository(['conditional' => $conditional]))->registerWith($engine);

        self::assertSame(['conditional'], array_keys($set->tasks));
    }

    public function testMergesEngineRefusalsAndSourceProblems(): void
    {
        $engine = new FakeEngine(refusals: [new TaskProblem('b', 'fake', 'bad options'), new TaskProblem(null, 'fake', 'general')]);
        $set = new Scheduler(new CompositeTaskSource([
            new InMemoryTaskRepository(['a' => Tasks::make('a'), 'b' => Tasks::make('b'), 'dup' => Tasks::make('dup')]),
            new InMemoryTaskRepository(['dup' => Tasks::make('dup')]),
        ]))->registerWith($engine);

        self::assertSame(['a'], array_keys($set->tasks));
        self::assertSame(['dup', 'b', null], array_map(static fn (TaskProblem $problem) => $problem->taskId, $set->problems));
    }

    public function testTasksReturnsTheSourceContent(): void
    {
        $set = new Scheduler(new InMemoryTaskRepository(['a' => Tasks::make('a')]))->tasks();

        self::assertSame(['a'], array_keys($set->tasks));
    }

    private function scheduler(TaskSourceInterface $readOnly, InMemoryTaskRepository $runtime): Scheduler
    {
        return new Scheduler(new CompositeTaskSource([$readOnly, $runtime]), $runtime);
    }

    /** A read-only source that holds no task and reports the given problem. */
    private function source(TaskProblem $problem): TaskSourceInterface
    {
        return new readonly class($problem) implements TaskSourceInterface {
            public function __construct(private TaskProblem $problem) {}

            /**
             * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
             * in the set and every valid task is still returned.
             */
            public function load(): TaskSet
            {
                return new TaskSet([], [$this->problem]);
            }
        };
    }
}
