<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use Scheduling\Exception\StorageException;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;

final class FailingRunRepository implements RunRepositoryInterface
{
    /**
     * Stores one record.
     */
    public function append(RunRecord $record): void
    {
        throw new StorageException('append failed');
    }

    /**
     * The newest record of a task in the given state, or null if there is none.
     * Used to tell a run that ended after its max_runtime (RunState::Overran).
     */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord
    {
        throw new StorageException('lastOf failed');
    }
}
