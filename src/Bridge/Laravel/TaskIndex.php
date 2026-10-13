<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Scheduling\Task\TaskDefinition;

/**
 * Maps Laravel's mutex name of an event (`scheduler:task:<id>`) back to its task, so the lock slots and the skip
 * subscriber, which Laravel hands only an Event, know which task and which lock policy it is. An event not in the
 * index belongs to the application's own schedule. It is also the list of the tasks registered in this process,
 * which the run record reads instead of loading the tasks a second time.
 */
final class TaskIndex
{
    /** @var array<string, TaskDefinition> keyed by Laravel mutex name */
    private array $byMutexName = [];

    /**
     * Remembers a task registered with Laravel under the mutex name its event carries; a second task under the
     * same name replaces the first.
     */
    public function add(string $mutexName, TaskDefinition $task): void
    {
        $this->byMutexName[$mutexName] = $task;
    }

    /**
     * The task registered under a Laravel mutex name, or null for an event of the application's own schedule.
     */
    public function get(string $mutexName): ?TaskDefinition
    {
        return $this->byMutexName[$mutexName] ?? null;
    }

    /**
     * Every registered task, keyed by task id.
     *
     * @return array<string, TaskDefinition>
     */
    public function tasks(): array
    {
        $tasks = [];
        foreach ($this->byMutexName as $task) {
            $tasks[$task->id->value] = $task;
        }

        return $tasks;
    }
}
