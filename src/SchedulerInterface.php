<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskSet;

/**
 * The one entry point the application uses: it owns the task list and hands it to whichever engine
 * runs it. Application code depends on this interface only, never on Laravel or crunz, which is
 * what makes the engine swappable.
 */
interface SchedulerInterface
{
    /**
     * Every task from every source (YAML, database), with the problems found while reading them.
     * A bad row or an unreadable source is reported, never thrown: the other tasks still run.
     */
    public function tasks(): TaskSet;

    /**
     * Adds a task to the writable source (the database); the engine picks it up on its next tick. It never replaces
     * a task: to change one, remove it and schedule it again.
     *
     * @throws TaskConflictException    when a task with this id is already scheduled
     * @throws TaskNotWritableException when the id belongs to a read-only source or no writable source is configured
     * @throws StorageException         when the writable source cannot store it
     */
    public function schedule(TaskDefinition $task): void;

    /**
     * Keeps the task but stops it running, from the next tick on.
     *
     * @throws TaskNotFoundException    when no task with this id is scheduled
     * @throws TaskNotWritableException when the id belongs to a read-only source or no writable source is configured
     * @throws StorageException         when the writable source cannot store it
     */
    public function disable(TaskId $id): void;

    /**
     * Deletes the task from the writable source.
     *
     * @throws TaskNotFoundException    when no task with this id is scheduled
     * @throws TaskNotWritableException when the id belongs to a read-only source or no writable source is configured
     * @throws StorageException         when the writable source cannot store it
     */
    public function remove(TaskId $id): void;

    /**
     * Gives the current task list to an engine. A task needing a capability the engine reports as
     * unavailable is refused before the engine sees it; tasks the engine itself refuses come back as problems.
     *
     * @return TaskSet the tasks the engine registered, and every problem: from the sources, the
     *                 capability check and the engine
     */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet;
}
