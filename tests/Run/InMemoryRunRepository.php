<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;

final class InMemoryRunRepository implements RunRepositoryInterface
{
    /** @var list<RunRecord> */
    public array $records = [];

    /**
     * Stores one record.
     */
    public function append(RunRecord $record): void
    {
        $this->records[] = $record;
    }

    /**
     * The newest record of a task in the given state, or null if there is none.
     * This is how a reader of the run history learns, e.g., when a task last finished or last overran.
     */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord
    {
        $match = null;
        foreach ($this->records as $record) {
            if ($record->taskId->value === $id->value && $record->state === $state) {
                $match = $record;
            }
        }

        return $match;
    }
}
