<?php declare(strict_types=1);

namespace Scheduling\Task;

interface TaskRepositoryInterface extends TaskSourceInterface
{
    public function save(TaskDefinition $task): void;

    public function disable(TaskId $id): void;

    public function remove(TaskId $id): void;
}
