<?php declare(strict_types=1);

namespace Scheduling\Task;

final readonly class TaskProblem
{
    public function __construct(
        public ?string $taskId,
        public string $source,
        public string $reason,
    ) {}
}
