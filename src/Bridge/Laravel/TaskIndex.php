<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Scheduling\Task\TaskDefinition;

final class TaskIndex
{
    /** @var array<string, TaskDefinition> keyed by Laravel mutex name */
    private array $byMutexName = [];

    public function add(string $mutexName, TaskDefinition $task): void
    {
        $this->byMutexName[$mutexName] = $task;
    }

    public function get(string $mutexName): ?TaskDefinition
    {
        return $this->byMutexName[$mutexName] ?? null;
    }
}
