<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\StorageException;

interface TaskRepositoryInterface extends TaskSourceInterface
{
    /** @throws StorageException */
    public function save(TaskDefinition $task): void;

    /** @throws StorageException */
    public function disable(TaskId $id): void;

    /** @throws StorageException */
    public function remove(TaskId $id): void;
}
