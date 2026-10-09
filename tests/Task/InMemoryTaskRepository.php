<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskRepositoryInterface;
use Scheduling\Task\TaskSet;

final class InMemoryTaskRepository implements TaskRepositoryInterface
{
    /** @param array<string, TaskDefinition> $tasks */
    public function __construct(private array $tasks = []) {}

    /**
     * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
     * in the set and every valid task is still returned.
     */
    public function load(): TaskSet
    {
        return new TaskSet($this->tasks);
    }

    /**
     * Adds the task; an id that is already stored is refused, never replaced.
     *
     * @throws TaskConflictException when a task with this id is already stored
     */
    public function add(TaskDefinition $task): void
    {
        if (isset($this->tasks[$task->id->value])) {
            throw new TaskConflictException("Task {$task->id->value} is already scheduled");
        }
        $this->tasks[$task->id->value] = $task;
    }

    /**
     * Marks the task disabled; it stays stored but does not run.
     *
     * @throws TaskNotFoundException when no task with this id is stored
     */
    public function disable(TaskId $id): void
    {
        $task = $this->tasks[$id->value] ?? throw new TaskNotFoundException("Task {$id->value} is not scheduled");
        $this->tasks[$id->value] = new TaskDefinition(
            $task->id,
            $task->schedule,
            $task->command,
            $task->timezone,
            $task->lock,
            false,
            $task->options,
            $task->conditions,
        );
    }

    /**
     * Deletes the task.
     *
     * @throws TaskNotFoundException when no task with this id is stored
     */
    public function remove(TaskId $id): void
    {
        if (!isset($this->tasks[$id->value])) {
            throw new TaskNotFoundException("Task {$id->value} is not scheduled");
        }
        unset($this->tasks[$id->value]);
    }
}
