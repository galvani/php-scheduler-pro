<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;

/**
 * Condition: the task runs only in the listed environments. The current environment is fixed at construction;
 * the time passed to isSatisfied is ignored.
 */
final readonly class Environments implements ConditionInterface
{
    /**
     * @param non-empty-list<string> $allowed environment names that may run the task
     * @param string                 $current the environment of this process
     */
    public function __construct(
        public array $allowed,
        private string $current,
    ) {}

    /** {@inheritDoc} */
    public function isSatisfied(DateTimeImmutable $now): bool
    {
        return in_array($this->current, $this->allowed, true);
    }
}
