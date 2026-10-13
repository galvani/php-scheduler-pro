<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Scheduling\Task\TaskId;

/**
 * One entry in a task's run history: which state it entered, when, on which server, and in which run.
 */
final readonly class RunRecord
{
    /**
     * @param DateTimeImmutable $at     when the engine reported the state
     * @param string            $host   the server that reported it
     * @param string|null       $detail the error message or exit code for a failure, the skip reason for a skip, otherwise null
     * @param RunId|null        $runId  the run the record belongs to; null for a skip, which has no run
     */
    public function __construct(
        public TaskId $taskId,
        public RunState $state,
        public DateTimeImmutable $at,
        public string $host,
        public ?string $detail = null,
        public ?RunId $runId = null,
    ) {}
}
