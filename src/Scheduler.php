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

/**
 * Default SchedulerInterface. Reads the task list from one source (normally a CompositeTaskSource over
 * YAML and the database) and writes only to the optional writable repository.
 * Trap: writability is decided per id. An id found in the source but not in the writable repository
 * comes from a read-only source and is refused; an id found nowhere is new and goes to the repository.
 */
final readonly class Scheduler implements SchedulerInterface
{
    /**
     * @param TaskSourceInterface          $source  every readable task, read-only sources included
     * @param TaskRepositoryInterface|null $runtime the writable store; null means every write is refused
     */
    public function __construct(
        private TaskSourceInterface $source,
        private ?TaskRepositoryInterface $runtime = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Loads the source on every call; nothing is cached.
     */
    public function tasks(): TaskSet
    {
        return $this->source->load();
    }

    /**
     * {@inheritDoc}
     *
     * Saves the definition to the writable repository after the writability check.
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
     * Delegates to the writable repository, which rewrites the stored definition with enabled=false.
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

    /**
     * {@inheritDoc}
     *
     * A task with conditions is refused, with the engine's name as problem source, when the engine reports
     * Capability::Conditions as unavailable. Tasks named in the engine's own refusals are dropped from the
     * returned tasks; a refusal without a task id is only reported.
     */
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

    /**
     * Returns the writable repository for $id, or refuses: there is none, or the id lives only in a read-only source.
     *
     * @throws TaskNotWritableException
     */
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
