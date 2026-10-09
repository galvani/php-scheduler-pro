<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\StorageException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Throwable;

final class RecordingRunListener implements RunListenerInterface
{
    /** @param array<string, TaskDefinition> $tasks */
    public function __construct(
        private readonly RunRepositoryInterface $runs,
        private readonly array $tasks,
        private readonly string $host,
        private readonly LoggerInterface $logger,
    ) {}

    /** {@inheritDoc} */
    public function started(TaskId $id, DateTimeImmutable $at): void
    {
        $this->record(new RunRecord($id, RunState::Started, $at, $this->host));
    }

    /** {@inheritDoc} */
    public function finished(TaskId $id, DateTimeImmutable $at): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Finished;
        $this->record(new RunRecord($id, $state, $at, $this->host));
    }

    /** {@inheritDoc} */
    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Failed;
        $detail = $error?->getMessage() ?? (null !== $exitCode ? "exit code {$exitCode}" : null);
        $this->record(new RunRecord($id, $state, $at, $this->host, $detail));
    }

    /** {@inheritDoc} */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->record(new RunRecord($id, RunState::Skipped, $at, $this->host, $reason->value));
    }

    private function record(RunRecord $record): void
    {
        try {
            $this->runs->append($record);
        } catch (StorageException $e) {
            $this->logFailure($record->taskId, $e);
        }
    }

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
