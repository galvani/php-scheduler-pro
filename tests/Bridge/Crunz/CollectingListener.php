<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use DateTimeImmutable;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\Task\TaskId;
use Throwable;

final class CollectingListener implements RunListenerInterface
{
    /** @var list<string> "<event>:<task id>" or "skipped:<task id>:<reason>" */
    public array $calls = [];

    /**
     * The run began; the engine holds the task's locks.
     */
    public function started(TaskId $id, DateTimeImmutable $at): void
    {
        $this->calls[] = "started:{$id->value}";
    }

    /**
     * The run ended successfully.
     */
    public function finished(TaskId $id, DateTimeImmutable $at): void
    {
        $this->calls[] = "finished:{$id->value}";
    }

    /**
     * The run ended with an error.
     *
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $this->calls[] = "failed:{$id->value}";
    }

    /**
     * The run was due but did not start.
     */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->calls[] = "skipped:{$id->value}:{$reason->value}";
    }
}
