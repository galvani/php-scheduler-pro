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
 * runtime since its recorded start is stored as `overran`, not `finished` or `failed`.
 * A storage failure is logged as a warning and never stops a task.
 */
final class RecordingRunListener implements RunListenerInterface
{
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
     */
    public function started(TaskId $id, DateTimeImmutable $at): void
    {
        $this->record(new RunRecord($id, RunState::Started, $at, $this->host));
    }

    /**
     * The run ended successfully.
     */
    public function finished(TaskId $id, DateTimeImmutable $at): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Finished;
        $this->record(new RunRecord($id, $state, $at, $this->host));
    }

    /**
     * The run ended with an error.
     *
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Failed;
        $detail = $error?->getMessage() ?? (null !== $exitCode ? "exit code {$exitCode}" : null);
        $this->record(new RunRecord($id, $state, $at, $this->host, $detail));
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
            $this->logFailure($record->taskId, $e);
        }
    }

    /**
     * Whether the run lasted longer than the task's max runtime, measured from the last recorded start.
     * False when there is no start record, the task is unknown or the history cannot be read.
     */
    private function overran(TaskId $id, DateTimeImmutable $end): bool
    {
        try {
            $start = $this->runs->lastOf($id, RunState::Started);
        } catch (StorageException $e) {
            $this->logFailure($id, $e);

            return false;
        }
        $task = $this->tasks[$id->value] ?? null;
        if (null === $start || null === $task) {
            return false;
        }

        return $end->getTimestamp() - $start->at->getTimestamp() > $task->lock->maxRuntimeSeconds;
    }

    private function logFailure(TaskId $id, StorageException $e): void
    {
        $this->logger->warning('Run history is unavailable', ['task_id' => $id->value, 'exception' => $e]);
    }
}
