<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Exception\TaskNotWritableException;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskSet;

interface SchedulerInterface
{
    public function tasks(): TaskSet;

    /** @throws TaskNotWritableException when the id belongs to a read-only source */
    public function schedule(TaskDefinition $task): void;

    /** @throws TaskNotWritableException */
    public function disable(TaskId $id): void;

    /** @throws TaskNotWritableException */
    public function remove(TaskId $id): void;

    public function registerWith(SchedulerEngineInterface $engine): TaskSet;
}
