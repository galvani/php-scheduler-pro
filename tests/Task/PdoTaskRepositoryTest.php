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

    /** @throws StorageException */
    public function testSavedTaskLoadsBackEqual(): void
    {
        $task = Tasks::make('report', [new Environments(['production'], 'production')], timezone: 'America/New_York');
        $repository = $this->repository();

        $repository->save($task);
        $set = $repository->load();

        self::assertSame([], $set->problems);
        self::assertEquals(['report' => $task], $set->tasks);
    }

    /** @throws StorageException */
    public function testSaveReplacesAnExistingTask(): void
    {
        $repository = $this->repository();
        $repository->save(Tasks::make('report'));
        $repository->save(Tasks::make('report', enabled: false));

        $set = $repository->load();

        self::assertCount(1, $set->tasks);
        self::assertFalse($set->tasks['report']->enabled);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testDisableKeepsTheRestOfTheDefinition(): void
    {
        $repository = $this->repository();
        $task = Tasks::make('report');
        $repository->save($task);

        $repository->disable(new TaskId('report'));

        $loaded = $repository->load()->tasks['report'];
        self::assertFalse($loaded->enabled);
        self::assertEquals(Tasks::make('report', enabled: false), $loaded);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testDisablingAMissingTaskChangesNothing(): void
    {
        $repository = $this->repository();

        $repository->disable(new TaskId('absent'));

        self::assertSame([], $repository->load()->tasks);
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testRemoveDeletesOnlyThatTask(): void
    {
        $repository = $this->repository();
        $repository->save(Tasks::make('a'));
        $repository->save(Tasks::make('b'));

        $repository->remove(new TaskId('a'));

        self::assertSame(['b'], array_keys($repository->load()->tasks));
    }

    /**
     * @throws PDOException
     * @throws StorageException
     */
    public function testCorruptRowsAreProblemsAndTheOthersStillLoad(): void
    {
        $repository = $this->repository();
        $repository->save(Tasks::make('good'));
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
     */
    public function testWriteFailureThrowsStorageException(): void
    {
        $this->pdo->exec('DROP TABLE scheduler_tasks');
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->save(Tasks::make('report'));
    }

    /**
     * @throws InvalidTaskException
     * @throws PDOException
     * @throws StorageException
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
     */
    public function testDisableFailureThrowsStorageException(): void
    {
        $this->pdo->exec('DROP TABLE scheduler_tasks');
        $repository = $this->repository();

        $this->expectException(StorageException::class);

        $repository->disable(new TaskId('report'));
    }

    /** @throws PDOException */
    public function testFailedWriteLeavesNoOpenTransaction(): void
    {
        $repository = $this->repository();
        $this->pdo->exec('DROP TABLE scheduler_tasks');

        try {
            $repository->save(Tasks::make('report'));
            self::fail('The write did not fail');
        } catch (StorageException) {
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    /**
     * @throws PDOException
     * @throws StorageException
     */
    public function testSaveInsideTheCallersTransactionLeavesItOpen(): void
    {
        $repository = $this->repository();
        $this->pdo->beginTransaction();

        $repository->save(Tasks::make('report'));

        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame([], $repository->load()->tasks);
    }

    /** @throws StorageException */
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

        $repository->save(Tasks::make('report', [$custom]));
    }

    /**
     * @throws PDOException
     * @throws StorageException
     */
    public function testUsesTheGivenTable(): void
    {
        $this->pdo->exec('CREATE TABLE jobs (id VARCHAR(100) PRIMARY KEY, definition TEXT NOT NULL)');
        $repository = new PdoTaskRepository($this->pdo, $this->mapper(), 'jobs');

        $repository->save(Tasks::make('report'));

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
