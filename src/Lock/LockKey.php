<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Scheduling\Task\TaskId;

final readonly class LockKey
{
    private function __construct(public string $value)
    {
    }

    public static function forTask(TaskId $id): self
    {
        return new self("scheduler:task:{$id->value}");
    }

    public static function forTaskAt(TaskId $id, DateTimeInterface $minute): self
    {
        $utc = DateTimeImmutable::createFromInterface($minute)->setTimezone(new DateTimeZone('UTC'));

        return new self("scheduler:task:{$id->value}:".$utc->format('YmdHi'));
    }
}
