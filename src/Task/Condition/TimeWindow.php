<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;
use Scheduling\Exception\InvalidTaskException;

/**
 * Condition: the task runs only when the local time of day falls inside a window, ends included.
 * The time is read in the task's timezone. A window whose end is earlier than its start crosses midnight.
 */
final class TimeWindow implements ConditionInterface
{
    private const string FORMAT = '/^([01]\d|2[0-3]):[0-5]\d$/D';

    public private(set) string $from {
        /** @throws InvalidTaskException */
        set => 1 === preg_match(self::FORMAT, $value) ? $value : throw new InvalidTaskException("Invalid time: {$value}");
    }

    public private(set) string $to {
        /** @throws InvalidTaskException */
        set => 1 === preg_match(self::FORMAT, $value) ? $value : throw new InvalidTaskException("Invalid time: {$value}");
    }

    /**
     * @param string $from "HH:MM", inclusive
     * @param string $to   "HH:MM", inclusive; earlier than $from means the window crosses midnight
     *
     * @throws InvalidTaskException
     */
    public function __construct(string $from, string $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * {@inheritDoc}
     *
     * Compares "H:i" strings, which sort correctly because the format is fixed-width.
     */
    public function isSatisfied(DateTimeImmutable $now): bool
    {
        $time = $now->format('H:i');

        return $this->from <= $this->to
            ? $time >= $this->from && $time <= $this->to
            : $time >= $this->from || $time <= $this->to;
    }
}
