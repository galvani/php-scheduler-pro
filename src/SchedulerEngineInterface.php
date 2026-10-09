<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

interface SchedulerEngineInterface
{
    /** @return non-empty-string */
    public function name(): string;

    /**
     * @param array<string, TaskDefinition> $tasks
     *
     * @return list<TaskProblem> tasks the engine refused, e.g. invalid adapter options
     */
    public function register(array $tasks): array;
}
