<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Scheduling\Task\TaskId;

final readonly class RunRecord
{
    public function __construct(
        public TaskId $taskId,
        public RunState $state,
        public DateTimeImmutable $at,
        public string $host,
        public ?string $detail = null,
    ) {}
}
