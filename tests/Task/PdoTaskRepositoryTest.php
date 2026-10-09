<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;
use Scheduling\Task\Condition\ConditionInterface;
use Scheduling\Task\Condition\Environments;
use Scheduling\Task\PdoTaskRepository;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\TaskId;

final class PdoTaskRepositoryTest extends TestCase
{
    private PDO $pdo;

    /** @throws PDOException */
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE scheduler_tasks (id VARCHAR(100) PRIMARY KEY, definition TEXT NOT NULL)');
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testSavedTaskLoadsBackEqual(): void
    {
        $task = Tasks::make('report', [new Environments(['production'], 'production')], timezone: 'America/New_York');
        $repository = $this->repository();

        $repository->add($task);
        $set = $repository->load();

        self::assertSame([], $set->problems);
        self::assertEquals(['report' => $task], $set->tasks);
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testAddingAnIdThatIsAlreadyStoredIsAConflictAndKeepsTheStoredTask(): void
    {
        $repository = $this->repository();
        $repository->add(Tasks::make('report'));

        try {
            $repository->add(Tasks::make('report', enabled: false));
            self::fail('A second add of the same id must be refused');
        } catch (TaskConflictException $e) {
            self::assertSame('Task report is already scheduled', $e->getMessage());
        }

        self::assertTrue($repository->load()->tasks['report']->enabled);
    }

    /**
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testTwoWritersAddingTheSameIdGetOneSuccessAndOneConflict(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scheduler-tasks-');
        self::assertIsString($file);
        try {
            $first = new PDO("sqlite:{$file}");
            $first->exec('CREATE TABLE scheduler_tasks (id VARCHAR(100) PRIMARY KEY, definition TEXT NOT NULL)');
            $second = new PDO("sqlite:{$file}");
            new PdoTaskRepository($first, $this->mapper())->add(Tasks::make('report'));

            $this->expectException(TaskConflictException::class);

            new PdoTaskRepository($second, $this->mapper())->add(Tasks::make('report', enabled: false));
        } finally {
            unlink($file);
        }
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testDisableKeepsTheRestOfTheDefinition(): void
    {
        $repository = $this->repository();
        $task = Tasks::make('report');
        $repository->add($task);

        $repository->disable(new TaskId('report'));

        $loaded = $repository->load()->tasks['report'];
        self::assertFalse($loaded->enabled);
        self::assertEquals(Tasks::make('report', enabled: false), $loaded);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskNotFoundException
     */
    public function testDisablingAMissingTaskIsNotFound(): void
    {
        $this->expectException(TaskNotFoundException::class);
        $this->expectExceptionMessage('Task absent is not scheduled');

        $this->repository()->disable(new TaskId('absent'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testRemovingTheSameTaskTwiceIsNotFoundTheSecondTime(): void
    {
        $repository = $this->repository();
        $repository->add(Tasks::make('report'));
        $repository->remove(new TaskId('report'));

        $this->expectException(TaskNotFoundException::class);

        $repository->remove(new TaskId('report'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testATaskIsChangedByRemovingItAndAddingItAgain(): void
    {
        $repository = $this->repository();
        $repository->add(Tasks::make('report'));

        $repository->remove(new TaskId('report'));
        $repository->add(Tasks::make('report', enabled: false));

        self::assertFalse($repository->load()->tasks['report']->enabled);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     * @throws TaskConflictException
     * @throws TaskNotFoundException
     */
    public function testRemoveDeletesOnlyThatTask(): void
    {
        $repository = $this->repository();
        $repository->add(Tasks::make('a'));
        $repository->add(Tasks::make('b'));

        $repository->remove(new TaskId('a'));

        self::assertSame(['b'], array_keys($repository->load()->tasks));
    }

    /**
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testCorruptRowsAreProblemsAndTheOthersStillLoad(): void
    {
        $repository = $this->repository();
        $repository->add(Tasks::make('good'));
        $insert = $this->pdo->prepare('INSERT INTO scheduler_tasks (id, definition) VALUES (?, ?)');
        $insert->execute(['broken-json', '{not json']);
        $insert->execute(['not-an-object', '"text"']);
        $insert->execute(['bad-row', '{"schedule":"nope","command":"a","max_runtime":60}']);
        $insert->execute(['Bad Id', '{"schedule":"0 6 * * *","command":"a","max_runtime":60}']);

        $set = $repository->load();

        self::assertSame(['good'], array_keys($set->tasks));
        $ids = array_map(static fn ($problem) => $problem->taskId, $set->problems);
        sort($ids);
        self::assertSame(['Bad Id', 'bad-row', 'broken-json', 'not-an-object'], $ids);
        self::assertSame('database', $set->problems[0]->source);
    }

    /** @throws PDOException */
    public function testReadFailureIsAProblemNotAnException(): void
    {
        $this->pdo->exec('DROP TABLE scheduler_tasks');

        $set = $this->repository()->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
        self::assertNull($set->problems[0]->taskId);
        self::assertSame('database', $set->problems[0]->source);
    }

    /** @throws PDOException */
    public function testReadFailureWithoutExceptionsIsAProblemToo(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->pdo->exec('DROP TABLE scheduler_tasks');

        $set = $this->repository()->load();

        self::assertCount(1, $set->problems);
    }

    /**
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testWriteFailureThrowsStorageException(): void
    {
        $this->pdo->exec('DROP TABLE scheduler_tasks');
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->add(Tasks::make('report'));
    }

    /**
     * @throws InvalidTaskException
     * @throws PDOException
     * @throws StorageException
     * @throws TaskNotFoundException
     */
    public function testWriteFailureWithoutExceptionsThrowsStorageException(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->pdo->exec('DROP TABLE scheduler_tasks');
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->remove(new TaskId('report'));
    }

    /**
     * @throws InvalidTaskException
     * @throws PDOException
     * @throws StorageException
     * @throws TaskNotFoundException
     */
    public function testDisableFailureThrowsStorageException(): void
    {
        $this->pdo->exec('DROP TABLE scheduler_tasks');
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->disable(new TaskId('report'));
    }

    /**
     * @throws PDOException
     * @throws TaskConflictException
     */
    public function testFailedWriteLeavesNoOpenTransaction(): void
    {
        $repository = $this->repository();
        $this->pdo->exec('DROP TABLE scheduler_tasks');

        try {
            $repository->add(Tasks::make('report'));
            self::fail('The write did not fail');
        } catch (StorageException) {
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    /**
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testAddInsideTheCallersTransactionLeavesItOpen(): void
    {
        $repository = $this->repository();
        $this->pdo->beginTransaction();

        $repository->add(Tasks::make('report'));

        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame([], $repository->load()->tasks);
    }

    /**
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testACustomConditionCannotBeStored(): void
    {
        $custom = new class implements ConditionInterface {
            /**
             * Whether the condition holds at the given moment.
             *
             * @param DateTimeImmutable $now in the task's time zone
             */
            public function isSatisfied(DateTimeImmutable $now): bool
            {
                return true;
            }
        };
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->add(Tasks::make('report', [$custom]));
    }

    /**
     * @throws PDOException
     * @throws StorageException
     * @throws TaskConflictException
     */
    public function testUsesTheGivenTable(): void
    {
        $this->pdo->exec('CREATE TABLE jobs (id VARCHAR(100) PRIMARY KEY, definition TEXT NOT NULL)');
        $repository = new PdoTaskRepository($this->pdo, $this->mapper(), 'jobs');

        $repository->add(Tasks::make('report'));

        $count = $this->pdo->query('SELECT COUNT(*) FROM jobs');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(1, $count->fetchColumn());
    }

    public function testRejectsAnInvalidTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PdoTaskRepository($this->pdo, $this->mapper(), 'tasks; DROP TABLE x');
    }

    private function repository(): PdoTaskRepository
    {
        return new PdoTaskRepository($this->pdo, $this->mapper());
    }

    private function mapper(): TaskDefinitionMapper
    {
        return new TaskDefinitionMapper(new DateTimeZone('UTC'), 'production');
    }
}
