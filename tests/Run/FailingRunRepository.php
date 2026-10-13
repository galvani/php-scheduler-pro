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
     * This is how a reader of the run history learns, e.g., when a task last finished or last overran.
     */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord
    {
        throw new StorageException('lastOf failed');
    }
}
