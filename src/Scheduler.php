<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Capability\Capability;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;
use Scheduling\Task\TaskRepositoryInterface;
use Scheduling\Task\TaskSet;
use Scheduling\Task\TaskSourceInterface;

final readonly class Scheduler implements SchedulerInterface
{
    public function __construct(
        private TaskSourceInterface $source,
        private ?TaskRepositoryInterface $runtime = null,
    ) {}

    /** {@inheritDoc} */
    public function tasks(): TaskSet
    {
        return $this->source->load();
    }

    /**
     * {@inheritDoc}
     *
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function schedule(TaskDefinition $task): void
    {
        $this->writable($task->id)->save($task);
    }

    /**
     * {@inheritDoc}
     *
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function disable(TaskId $id): void
    {
        $this->writable($id)->disable($id);
    }

    /**
     * {@inheritDoc}
     *
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function remove(TaskId $id): void
    {
        $this->writable($id)->remove($id);
    }

    /** {@inheritDoc} */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet
    {
        $set = $this->tasks();
        $conditionsUnavailable = Fulfilment::Unavailable === $engine->capabilities()->of(Capability::Conditions);
        $accepted = [];
        $unsupported = [];
        foreach ($set->tasks as $id => $task) {
            if ($conditionsUnavailable && [] !== $task->conditions) {
                $unsupported[] = new TaskProblem($id, $engine->name(), 'The engine cannot evaluate conditions');
                continue;
            }
            $accepted[$id] = $task;
        }
        $refused = [...$unsupported, ...$engine->register($accepted)];

        $registered = $accepted;
        foreach ($refused as $problem) {
            if (null !== $problem->taskId) {
                unset($registered[$problem->taskId]);
            }
        }

        return new TaskSet($registered, [...$set->problems, ...$refused]);
    }

    /** @throws TaskNotWritableException */
    private function writable(TaskId $id): TaskRepositoryInterface
    {
        if (null === $this->runtime) {
            throw new TaskNotWritableException('No writable task source is configured');
        }
        $everywhere = $this->source->load()->tasks;
        $runtime = $this->runtime->load()->tasks;
        if (isset($everywhere[$id->value]) && !isset($runtime[$id->value])) {
            throw new TaskNotWritableException("Task {$id->value} is defined in a read-only source");
        }

        return $this->runtime;
    }
}
