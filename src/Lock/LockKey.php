<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Scheduling\Task\TaskId;

/**
 * The only place lock keys are built, so servers on different engines compute the same key for the same task
 * and exclude each other. The constructor is private on purpose.
 */
final readonly class LockKey
{
    private function __construct(public string $value) {}

    /** The overlap lock of a task: `scheduler:task:<id>`, held for the duration of a run. */
    public static function forTask(TaskId $id): self
    {
        return new self("scheduler:task:{$id->value}");
    }

    /**
     * The one-server claim of a task for one minute: `scheduler:task:<id>:<YmdHi>`, so one server out of many
     * runs that minute.
     *
     * @param DateTimeInterface $minute any moment inside the minute; converted to UTC so servers in other timezones agree
     */
    public static function forTaskAt(TaskId $id, DateTimeInterface $minute): self
    {
        $utc = DateTimeImmutable::createFromInterface($minute)->setTimezone(new DateTimeZone('UTC'));

        return new self("scheduler:task:{$id->value}:".$utc->format('YmdHi'));
    }
}
