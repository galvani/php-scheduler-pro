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

    /** {@inheritDoc} */
    public function load(): TaskSet
    {
        return new TaskSet($this->tasks);
    }

    /** {@inheritDoc} */
    public function save(TaskDefinition $task): void
    {
        $this->tasks[$task->id->value] = $task;
    }

    /** {@inheritDoc} */
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

    /** {@inheritDoc} */
    public function remove(TaskId $id): void
    {
        unset($this->tasks[$id->value]);
    }
}
