<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;
use Scheduling\Exception\InvalidTaskException;

final class TimeWindow implements ConditionInterface
{
    private const string FORMAT = '/^([01]\d|2[0-3]):[0-5]\d$/';

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

    /** {@inheritDoc} */
    public function isSatisfied(DateTimeImmutable $now): bool
    {
        $time = $now->format('H:i');

        return $this->from <= $this->to
            ? $time >= $this->from && $time <= $this->to
            : $time >= $this->from || $time <= $this->to;
    }
}
