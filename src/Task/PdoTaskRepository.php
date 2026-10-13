<?php declare(strict_types=1);

namespace Scheduling\Task;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;

/**
 * Writable task source on a PDO table. Each row holds the task id and its definition as JSON, in the same
 * shape as a YAML entry, converted by TaskDefinitionMapper.
 * Reads never throw: a failed query, a malformed row or invalid JSON becomes a TaskProblem. Writes throw StorageException.
 */
final readonly class PdoTaskRepository implements TaskRepositoryInterface
{
    private const string SAVEPOINT = 'scheduler_add';

    /**
     * @param string $table table with an `id` and a `definition` column; only letters, digits and underscores,
     *                      because it is placed into the SQL text
     *
     * @throws InvalidArgumentException when the table name has other characters
     */
    public function __construct(
        private PDO $pdo,
        private TaskDefinitionMapper $mapper,
        private string $table = 'scheduler_tasks',
    ) {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
     * in the set and every valid task is still returned.
     */
    public function load(): TaskSet
    {
        try {
            $rows = $this->run("SELECT id, definition FROM {$this->table}", [])->fetchAll(PDO::FETCH_NUM);
        } catch (PDOException|StorageException $e) {
            return new TaskSet([], [new TaskProblem(null, 'database', $e->getMessage())]);
        }

        $tasks = [];
        $problems = [];
        foreach ($rows as $stored) {
            if (!is_array($stored) || !is_string($id = $stored[0] ?? null) || !is_string($definition = $stored[1] ?? null)) {
                $problems[] = new TaskProblem(null, 'database', 'A stored row is malformed');
                continue;
            }
            try {
                $row = json_decode($definition, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($row)) {
                    throw new InvalidTaskException('Stored definition is not an object');
                }
                $tasks[$id] = $this->mapper->fromArray($id, $row);
            } catch (JsonException|InvalidTaskException $e) {
                $problems[] = new TaskProblem($id, 'database', $e->getMessage());
            }
        }

        return new TaskSet($tasks, $problems);
    }

    /**
     * Adds the task. An id that is already stored is refused, never replaced; the storage itself decides, so two
     * writers adding the same id at once get one success and one conflict.
     *
     * A plain INSERT: the table's primary key rejects a second row with the same id, and only that rejection becomes
     * the conflict (SQLSTATE 23505 on PostgreSQL, error 1062 on MySQL, a UNIQUE constraint failure on SQLite); any
     * other failure, another integrity violation included, is a StorageException. Inside the caller's transaction the
     * INSERT runs in a savepoint that is rolled back when it fails, because PostgreSQL would otherwise abort the
     * caller's whole transaction.
     *
     * @throws TaskConflictException when a task with this id is already stored
     * @throws StorageException      when the storage cannot be written or the task cannot be stored as data
     */
    public function add(TaskDefinition $task): void
    {
        try {
            $definition = json_encode($this->mapper->toArray($task), JSON_THROW_ON_ERROR);
        } catch (JsonException|InvalidTaskException $e) {
            throw new StorageException("Task {$task->id->value} cannot be stored", previous: $e);
        }
        $savepoint = $this->pdo->inTransaction();
        if ($savepoint) {
            $this->execute('SAVEPOINT '.self::SAVEPOINT);
        }
        try {
            $this->run("INSERT INTO {$this->table} (id, definition) VALUES (?, ?)", [$task->id->value, $definition]);
        } catch (StorageException $e) {
            if ($savepoint) {
                $this->execute('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
                $this->execute('RELEASE SAVEPOINT '.self::SAVEPOINT);
            }
            $cause = $e->getPrevious();
            if ($cause instanceof PDOException && $this->isDuplicateKey($cause)) {
                throw new TaskConflictException("Task {$task->id->value} is already scheduled", previous: $e);
            }
            throw $e;
        }
        if ($savepoint) {
            $this->execute('RELEASE SAVEPOINT '.self::SAVEPOINT);
        }
    }

    /**
     * Marks the task disabled; it stays stored but does not run.
     *
     * Reads the stored definition, sets `enabled: false` and writes it back with an UPDATE; no row read, or no row
     * updated because the task was removed in between, means the task is not there. MySQL counts only the rows an
     * UPDATE changed, so an UPDATE that changed nothing is checked against a fresh read before it means "not there".
     *
     * @throws TaskNotFoundException when no task with this id is stored
     * @throws StorageException      when the storage cannot be read or written
     */
    public function disable(TaskId $id): void
    {
        try {
            $stored = $this->run("SELECT definition FROM {$this->table} WHERE id = ?", [$id->value])->fetchColumn();
        } catch (PDOException $e) {
            throw new StorageException("Task {$id->value} cannot be read", previous: $e);
        }
        if (false === $stored) {
            throw new TaskNotFoundException("Task {$id->value} is not scheduled");
        }
        try {
            $row = is_string($stored) ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!is_array($row)) {
                throw new StorageException("Stored definition of {$id->value} is not an object");
            }
            $row['enabled'] = false;
            $definition = json_encode($row, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageException("Task {$id->value} cannot be disabled", previous: $e);
        }
        if (0 === $this->changedRows("UPDATE {$this->table} SET definition = ? WHERE id = ?", [$definition, $id->value])
            && !$this->exists($id)) {
            throw new TaskNotFoundException("Task {$id->value} is not scheduled");
        }
    }

    /**
     * Deletes the task.
     *
     * The number of rows the DELETE removed is the answer: none means the task is not there.
     *
     * @throws TaskNotFoundException when no task with this id is stored
     * @throws StorageException      when the storage cannot be written
     */
    public function remove(TaskId $id): void
    {
        if (0 === $this->changedRows("DELETE FROM {$this->table} WHERE id = ?", [$id->value])) {
            throw new TaskNotFoundException("Task {$id->value} is not scheduled");
        }
    }

    /**
     * Whether a row with this id is stored.
     *
     * @throws StorageException
     */
    private function exists(TaskId $id): bool
    {
        try {
            return false !== $this->run("SELECT 1 FROM {$this->table} WHERE id = ?", [$id->value])->fetchColumn();
        } catch (PDOException $e) {
            throw new StorageException("Task {$id->value} cannot be read", previous: $e);
        }
    }

    /**
     * Whether the failure is the primary key refusing a second row with the same id, as opposed to any other
     * integrity violation (NOT NULL, CHECK, foreign key), which shares SQLSTATE class 23 on every driver.
     */
    private function isDuplicateKey(PDOException $e): bool
    {
        [$state, $driverCode, $message] = ($e->errorInfo ?? []) + [null, null, null];

        return '23505' === $state
            || ('23000' === $state && 1062 === $driverCode)
            || ('23000' === $state && 19 === $driverCode && is_string($message) && str_starts_with($message, 'UNIQUE constraint failed'));
    }

    /**
     * Runs a statement without parameters or a result, for the savepoint commands.
     *
     * @throws StorageException
     */
    private function execute(string $sql): void
    {
        try {
            if (false === $this->pdo->exec($sql)) {
                throw new StorageException("Query failed: {$sql}");
            }
        } catch (PDOException $e) {
            throw new StorageException("Query failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Runs a write and returns how many rows it changed, the database's own answer to "was the task there".
     *
     * @param list<string> $parameters
     *
     * @throws StorageException
     */
    private function changedRows(string $sql, array $parameters): int
    {
        $statement = $this->run($sql, $parameters);
        try {
            return $statement->rowCount();
        } catch (PDOException $e) {
            throw new StorageException("Query failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param list<string> $parameters
     *
     * @throws StorageException
     */
    private function run(string $sql, array $parameters): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (false === $statement || !$statement->execute($parameters)) {
                throw new StorageException("Query failed: {$sql}");
            }
        } catch (PDOException $e) {
            throw new StorageException("Query failed: {$e->getMessage()}", previous: $e);
        }

        return $statement;
    }
}
