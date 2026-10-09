<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;

/**
 * A rule checked when a task is due, on top of its cron expression: the task runs only if every
 * condition holds. Engines evaluate it through their own when(); a failed condition is recorded as
 * skipped with SkipReason::ConditionNotMet. Built in: TimeWindow, Environments.
 */
interface ConditionInterface
{
    /** @param DateTimeImmutable $now in the task's time zone */
    public function isSatisfied(DateTimeImmutable $now): bool;
}
