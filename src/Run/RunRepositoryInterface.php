<?php declare(strict_types=1);

namespace Scheduling\Run;

use Scheduling\Task\TaskId;

interface RunRepositoryInterface
{
    public function append(RunRecord $record): void;

    public function lastOf(TaskId $id, RunState $state): ?RunRecord;
}
