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

    /** {@inheritDoc} */
    public function append(RunRecord $record): void
    {
        $this->records[] = $record;
    }

    /** {@inheritDoc} */
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
