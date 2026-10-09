<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
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
    ) {
    }

    public function started(TaskId $id, DateTimeImmutable $at): void
    {
        $this->runs->append(new RunRecord($id, RunState::Started, $at, $this->host));
    }

    public function finished(TaskId $id, DateTimeImmutable $at): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Finished;
        $this->runs->append(new RunRecord($id, $state, $at, $this->host));
    }

    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $state = $this->overran($id, $at) ? RunState::Overran : RunState::Failed;
        $detail = $error?->getMessage() ?? (null !== $exitCode ? "exit code {$exitCode}" : null);
        $this->runs->append(new RunRecord($id, $state, $at, $this->host, $detail));
    }

    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->runs->append(new RunRecord($id, RunState::Skipped, $at, $this->host, $reason->value));
    }

    private function overran(TaskId $id, DateTimeImmutable $end): bool
    {
        $start = $this->runs->lastOf($id, RunState::Started);
        $task = $this->tasks[$id->value] ?? null;
        if (null === $start || null === $task) {
            return false;
        }

        return $end->getTimestamp() - $start->at->getTimestamp() > $task->lock->maxRuntimeSeconds;
    }
}
