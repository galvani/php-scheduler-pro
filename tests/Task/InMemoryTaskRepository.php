<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

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
     * Inserts the task, or replaces the stored one with the same id.
     */
    public function save(TaskDefinition $task): void
    {
        $this->tasks[$task->id->value] = $task;
    }

    /**
     * Marks the task disabled; an unknown id is a no-op.
     */
    public function disable(TaskId $id): void
    {
        $task = $this->tasks[$id->value] ?? null;
        if (null !== $task) {
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
    }

    /**
     * Deletes the task; an unknown id is a no-op.
     */
    public function remove(TaskId $id): void
    {
        unset($this->tasks[$id->value]);
    }
}
