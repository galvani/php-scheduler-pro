<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Scheduling\Task\TaskId;
use Throwable;

interface RunListenerInterface
{
    public function started(TaskId $id, DateTimeImmutable $at): void;

    public function finished(TaskId $id, DateTimeImmutable $at): void;

    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void;

    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void;
}
