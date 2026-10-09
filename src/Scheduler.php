<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskRepositoryInterface;
use Scheduling\Task\TaskSet;
use Scheduling\Task\TaskSourceInterface;

final readonly class Scheduler implements SchedulerInterface
{
    public function __construct(
        private TaskSourceInterface $source,
        private TaskRepositoryInterface $runtime,
    ) {
    }

    public function tasks(): TaskSet
    {
        return $this->source->load();
    }

    /** @throws TaskNotWritableException */
    public function schedule(TaskDefinition $task): void
    {
        $this->assertWritable($task->id);
        $this->runtime->save($task);
    }

    /** @throws TaskNotWritableException */
    public function disable(TaskId $id): void
    {
        $this->assertWritable($id);
        $this->runtime->disable($id);
    }

    /** @throws TaskNotWritableException */
    public function remove(TaskId $id): void
    {
        $this->assertWritable($id);
        $this->runtime->remove($id);
    }

    public function registerWith(SchedulerEngineInterface $engine): TaskSet
    {
        $set = $this->tasks();
        $refused = $engine->register($set->tasks);

        $registered = $set->tasks;
        foreach ($refused as $problem) {
            if (null !== $problem->taskId) {
                unset($registered[$problem->taskId]);
            }
        }

        return new TaskSet($registered, [...$set->problems, ...$refused]);
    }

    /** @throws TaskNotWritableException */
    private function assertWritable(TaskId $id): void
    {
        $everywhere = $this->source->load()->tasks;
        $runtime = $this->runtime->load()->tasks;
        if (isset($everywhere[$id->value]) && !isset($runtime[$id->value])) {
            throw new TaskNotWritableException("Task {$id->value} is defined in a read-only source");
        }
    }
}
