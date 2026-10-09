<?php declare(strict_types=1);

namespace Scheduling\Task;

/**
 * One thing that went wrong while reading or registering tasks, reported instead of thrown so the other tasks still run.
 *
 * $taskId is null when the problem is not tied to one task (an unreadable file, a failed query).
 * $source names where it arose ("yaml", "database", "composite" or an engine name); $reason is the message.
 */
final readonly class TaskProblem
{
    public function __construct(
        public ?string $taskId,
        public string $source,
        public string $reason,
    ) {}
}
