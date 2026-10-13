<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Capability\Capability;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\StorageException;
use Scheduling\Exception\TaskConflictException;
use Scheduling\Exception\TaskNotFoundException;
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
 * A read-only source that reports the id as invalid, or cannot be read at all, may own the id, so the write is
 * refused too: written to the repository, the id would turn the read-only copy into a skipped duplicate once
 * that source reads cleanly again.
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
     * Every task from every source (YAML, database), with the problems found while reading them.
     * A bad row or an unreadable source is reported, never thrown: the other tasks still run.
     *
     * Loads the source on every call; nothing is cached.
     */
    public function tasks(): TaskSet
    {
        return $this->source->load();
    }

    /**
     * Adds a task to the writable source (the database); the engine picks it up on its next tick. It never replaces
     * a task: to change one, remove it and schedule it again.
     *
     * The writable repository decides the conflict itself, so two processes adding the same id at once get one
     * success and one TaskConflictException.
     *
     * @throws TaskConflictException
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function schedule(TaskDefinition $task): void
    {
        $this->writable($task->id)->add($task);
    }

    /**
     * Keeps the task but stops it running, from the next tick on.
     *
     * Delegates to the writable repository, which rewrites the stored definition with enabled=false.
     *
     * @throws TaskNotFoundException
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function disable(TaskId $id): void
    {
        $this->writable($id)->disable($id);
    }

    /**
     * Deletes the task from the writable source.
     *
     * @throws TaskNotFoundException
     * @throws TaskNotWritableException
     * @throws StorageException
     */
    public function remove(TaskId $id): void
    {
        $this->writable($id)->remove($id);
    }

    /**
     * Gives the current task list to an engine. A task needing a capability the engine reports as
     * unavailable is refused before the engine sees it; tasks the engine itself refuses come back as problems.
     *
     * A task with conditions is refused, with the engine's name as problem source, when the engine reports
     * Capability::Conditions as unavailable. Tasks named in the engine's own refusals are dropped from the
     * returned tasks; a refusal without a task id is only reported.
     *
     * @return TaskSet the tasks handed to the engine that it did not refuse, disabled ones included (each engine
     *                 keeps them off the schedule), and every problem: from the sources, the capability check
     *                 and the engine
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
     * Returns the writable repository for $id, or refuses: there is none, the id lives only in a read-only source,
     * or a read-only source cannot tell whether it owns the id.
     *
     * An id the repository holds, as a task or as an invalid row, is always its own. Otherwise the problems of the
     * whole source that the repository did not report itself come from read-only sources: one without a task id
     * (an unreadable file) or one naming $id (an invalid entry) refuses the write.
     *
     * @throws TaskNotWritableException
     */
    private function writable(TaskId $id): TaskRepositoryInterface
    {
        if (null === $this->runtime) {
            throw new TaskNotWritableException('No writable task source is configured');
        }
        $runtime = $this->runtime->load();
        if (isset($runtime->tasks[$id->value]) || [] !== array_filter($runtime->problems, static fn (TaskProblem $problem) => $id->value === $problem->taskId)) {
            return $this->runtime;
        }
        $everywhere = $this->source->load();
        if (isset($everywhere->tasks[$id->value])) {
            throw new TaskNotWritableException("Task {$id->value} is defined in a read-only source");
        }
        foreach ($everywhere->problems as $problem) {
            if (in_array($problem, $runtime->problems)) {
                continue;
            }
            if (null === $problem->taskId) {
                throw new TaskNotWritableException("Task {$id->value} cannot be written while the {$problem->source} source cannot be read: {$problem->reason}");
            }
            if ($id->value === $problem->taskId) {
                throw new TaskNotWritableException("Task {$id->value} is defined in a read-only source, which reports it invalid: {$problem->reason}");
            }
        }

        return $this->runtime;
    }
}
