<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;

/**
 * A task source that can also be written at runtime (the database), so recurring tasks can be added,
 * disabled and removed without a deploy. Writes go through SchedulerInterface, which refuses ids
 * that belong to a read-only source.
 */
interface TaskRepositoryInterface extends TaskSourceInterface
{
    /**
     * Adds the task. An id that is already stored is refused, never replaced; the storage itself decides, so two
     * writers adding the same id at once get one success and one conflict.
     *
     * @throws TaskConflictException when a task with this id is already stored
     * @throws StorageException      when the storage cannot be written or the task cannot be stored as data
     */
    public function add(TaskDefinition $task): void;

    /**
     * Marks the task disabled; it stays stored but does not run.
     *
     * @throws TaskNotFoundException when no task with this id is stored
     * @throws StorageException      when the storage cannot be read or written
     */
    public function disable(TaskId $id): void;

    /**
     * Deletes the task.
     *
     * @throws TaskNotFoundException when no task with this id is stored
     * @throws StorageException      when the storage cannot be written
     */
    public function remove(TaskId $id): void;
}
