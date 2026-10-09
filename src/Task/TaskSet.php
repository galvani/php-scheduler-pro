<?php declare(strict_types=1);

namespace Scheduling\Task;

/**
 * The result of reading tasks: the valid ones keyed by id, and the problems found on the way.
 */
final readonly class TaskSet
{
    /**
     * @param array<string, TaskDefinition> $tasks    keyed by task id
     * @param list<TaskProblem>             $problems
     */
    public function __construct(
        public array $tasks = [],
        public array $problems = [],
    ) {}
}
