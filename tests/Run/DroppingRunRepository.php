<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use Scheduling\Exception\StorageException;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;

/** An in-memory repository whose next append fails once when asked to, like a short Redis outage. */
final class DroppingRunRepository implements RunRepositoryInterface
{
    /** @var list<RunRecord> */
    public array $records = [];

    public bool $dropNext = false;

    /**
     * Stores one record.
     *
     * @throws StorageException when the storage cannot be written
     */
    public function append(RunRecord $record): void
    {
        if ($this->dropNext) {
            $this->dropNext = false;

            throw new StorageException('append dropped');
        }
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
