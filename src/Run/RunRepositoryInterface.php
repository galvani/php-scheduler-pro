<?php declare(strict_types=1);

namespace Scheduling\Run;

use Scheduling\Exception\StorageException;
use Scheduling\Task\TaskId;

/**
 * Where run records are kept, shared by every server, so "did it run, where, and how did it end"
 * has one answer across the cluster.
 */
interface RunRepositoryInterface
{
    /**
     * Stores one record.
     *
     * @throws StorageException when the storage cannot be written
     */
    public function append(RunRecord $record): void;

    /**
     * The newest record of a task in the given state, or null if there is none.
     * Used to tell a run that ended after its max_runtime (RunState::Overran).
     *
     * @throws StorageException when the storage cannot be read
     */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord;
}
