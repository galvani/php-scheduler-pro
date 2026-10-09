<?php declare(strict_types=1);

namespace Scheduling\Run;

use Scheduling\Exception\StorageException;
use Scheduling\Task\TaskId;

interface RunRepositoryInterface
{
    /** @throws StorageException */
    public function append(RunRecord $record): void;

    /** @throws StorageException */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord;
}
