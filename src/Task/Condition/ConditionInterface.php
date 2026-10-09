<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;

interface ConditionInterface
{
    /** @param DateTimeImmutable $now in the task's time zone */
    public function isSatisfied(DateTimeImmutable $now): bool;
}
