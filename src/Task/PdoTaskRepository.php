<?php declare(strict_types=1);

namespace Scheduling\Task;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;

/**
 * Writable task source on a PDO table. Each row holds the task id and its definition as JSON, in the same
 * shape as a YAML entry, converted by TaskDefinitionMapper.
 * Reads never throw: a failed query, a malformed row or invalid JSON becomes a TaskProblem. Writes throw StorageException.
 */
final readonly class PdoTaskRepository implements TaskRepositoryInterface
{
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

    /** {@inheritDoc} */
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
     * {@inheritDoc}
     *
     * Replaces any row with the same id (delete then insert) inside one transaction.
     */
    public function save(TaskDefinition $task): void
    {
        try {
            $definition = json_encode($this->mapper->toArray($task), JSON_THROW_ON_ERROR);
        } catch (JsonException|InvalidTaskException $e) {
            throw new StorageException("Task {$task->id->value} cannot be stored", previous: $e);
        }
        $this->replace($task->id->value, $definition);
    }

    /**
     * {@inheritDoc}
     *
     * Sets `enabled` to false in the stored JSON without re-validating the rest. An unknown id is ignored.
     */
    public function disable(TaskId $id): void
    {
        try {
            $stored = $this->run("SELECT definition FROM {$this->table} WHERE id = ?", [$id->value])->fetchColumn();
            if (false === $stored) {
                return;
            }
            $row = is_string($stored) ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!is_array($row)) {
                throw new StorageException("Stored definition of {$id->value} is not an object");
            }
            $row['enabled'] = false;
            $this->replace($id->value, json_encode($row, JSON_THROW_ON_ERROR));
        } catch (JsonException|PDOException $e) {
            throw new StorageException("Task {$id->value} cannot be disabled", previous: $e);
        }
    }

    /** {@inheritDoc} */
    public function remove(TaskId $id): void
    {
        $this->run("DELETE FROM {$this->table} WHERE id = ?", [$id->value]);
    }

    /**
     * Delete and insert as one unit; joins the caller's transaction when one is open, otherwise opens and commits its own.
     *
     * @throws StorageException
     */
    private function replace(string $id, string $definition): void
    {
        $own = !$this->pdo->inTransaction();
        try {
            if ($own && !$this->pdo->beginTransaction()) {
                throw new StorageException('Cannot begin a transaction');
            }
            $this->run("DELETE FROM {$this->table} WHERE id = ?", [$id]);
            $this->run("INSERT INTO {$this->table} (id, definition) VALUES (?, ?)", [$id, $definition]);
            if ($own && !$this->pdo->commit()) {
                throw new StorageException('Cannot commit the transaction');
            }
        } catch (PDOException $e) {
            $this->rollBack($own);
            throw new StorageException("Task {$id} cannot be stored", previous: $e);
        } catch (StorageException $e) {
            $this->rollBack($own);
            throw $e;
        }
    }

    /** Rolls back only a transaction this repository opened, and ignores a failing rollback. */
    private function rollBack(bool $own): void
    {
        if ($own && $this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
            }
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
