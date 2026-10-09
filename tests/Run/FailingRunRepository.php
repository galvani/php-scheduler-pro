<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use Scheduling\Exception\StorageException;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;

final class FailingRunRepository implements RunRepositoryInterface
{
    /** {@inheritDoc} */
    public function append(RunRecord $record): void
    {
        throw new StorageException('append failed');
    }

    /** {@inheritDoc} */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord
    {
        throw new StorageException('lastOf failed');
    }
}
