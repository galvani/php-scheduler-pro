<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\StorageException;

/**
 * A task source that can also be written at runtime (the database), so recurring tasks can be added,
 * disabled and removed without a deploy. Writes go through SchedulerInterface, which refuses ids
 * that belong to a read-only source.
 */
interface TaskRepositoryInterface extends TaskSourceInterface
{
    /**
     * Inserts the task, or replaces the stored one with the same id.
     *
     * @throws StorageException when the storage cannot be written or the task cannot be stored as data
     */
    public function save(TaskDefinition $task): void;

    /**
     * Marks the task disabled; an unknown id is a no-op.
     *
     * @throws StorageException when the storage cannot be read or written
     */
    public function disable(TaskId $id): void;

    /**
     * Deletes the task; an unknown id is a no-op.
     *
     * @throws StorageException when the storage cannot be written
     */
    public function remove(TaskId $id): void;
}
