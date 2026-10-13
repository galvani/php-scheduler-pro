<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\StorageException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Throwable;

/**
 * Turns the engine's event stream into run records. A run that finishes or fails after more than the task's max
 * runtime since its own start is stored as `overran`, not `finished` or `failed`. The start time is kept in memory,
 * keyed by the run id started() returns, because the same process reports both ends of a run; a second copy that
 * started in between, or a start record the storage failed to write, does not change how the run is judged.
 * Every record of a run carries its run id. A storage failure is logged as a warning and never stops a task.
 */
final class RecordingRunListener implements RunListenerInterface
{
    /** @var array<string, DateTimeImmutable> start times of the runs in progress, keyed by run id */
    private array $starts = [];

    /**
     * @param array<string, TaskDefinition> $tasks the registered tasks keyed by id; their max runtime decides `overran`
     * @param string                        $host  recorded on every run record to tell servers apart
     */
    public function __construct(
        private readonly RunRepositoryInterface $runs,
        private readonly array $tasks,
        private readonly string $host,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The run began; the engine holds the task's locks.
     *
     * Generates a new run id, remembers the start time under it and appends the `started` record.
     *
     * @return RunId the id of this run, to pass to finished() or failed() when it ends
     */
    public function started(TaskId $id, DateTimeImmutable $at): RunId
    {
        $run = RunId::generate();
        $this->starts[$run->value] = $at;
        $this->record(new RunRecord($id, RunState::Started, $at, $this->host, null, $run));

        return $run;
    }

    /**
     * The run ended successfully.
     *
     * Records `overran` instead of `finished` when the run outlived the task's max runtime, and forgets the run.
     *
     * @param RunId $run the id started() returned for this run
     */
    public function finished(TaskId $id, RunId $run, DateTimeImmutable $at): void
    {
        $state = $this->overran($id, $run, $at) ? RunState::Overran : RunState::Finished;
        $this->record(new RunRecord($id, $state, $at, $this->host, null, $run));
    }

    /**
     * The run ended with an error.
     *
     * Records `overran` instead of `failed` when the run outlived the task's max runtime, and forgets the run.
     *
     * @param RunId          $run      the id started() returned for this run
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, RunId $run, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $state = $this->overran($id, $run, $at) ? RunState::Overran : RunState::Failed;
        $detail = $error?->getMessage() ?? (null !== $exitCode ? "exit code {$exitCode}" : null);
        $this->record(new RunRecord($id, $state, $at, $this->host, $detail, $run));
    }

    /**
     * The run was due but did not start.
     */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->record(new RunRecord($id, RunState::Skipped, $at, $this->host, $reason->value));
    }

    /** Appends the record; a StorageException is logged, not rethrown. */
    private function record(RunRecord $record): void
    {
        try {
            $this->runs->append($record);
        } catch (StorageException $e) {
            $this->logger->warning('Run history is unavailable', ['task_id' => $record->taskId->value, 'exception' => $e]);
        }
    }

    /**
     * Whether the run lasted longer than the task's max runtime, measured from this run's own start, and forgets the
     * start. False when the run id is unknown to this listener or the task is unknown.
     */
    private function overran(TaskId $id, RunId $run, DateTimeImmutable $end): bool
    {
        $start = $this->starts[$run->value] ?? null;
        unset($this->starts[$run->value]);
        $task = $this->tasks[$id->value] ?? null;
        if (null === $start || null === $task) {
            return false;
        }

        return $end->getTimestamp() - $start->getTimestamp() > $task->lock->maxRuntimeSeconds;
    }
}
