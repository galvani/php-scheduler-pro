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

    /** {@inheritDoc} */
    public function started(TaskId $id, DateTimeImmutable $at): void
    {
        $this->calls[] = "started:{$id->value}";
    }

    /** {@inheritDoc} */
    public function finished(TaskId $id, DateTimeImmutable $at): void
    {
        $this->calls[] = "finished:{$id->value}";
    }

    /** {@inheritDoc} */
    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $this->calls[] = "failed:{$id->value}";
    }

    /** {@inheritDoc} */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->calls[] = "skipped:{$id->value}:{$reason->value}";
    }
}
