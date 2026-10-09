<?php declare(strict_types=1);

namespace Scheduling\Task\Condition;

use DateTimeImmutable;

final readonly class Environments implements ConditionInterface
{
    /** @param non-empty-list<string> $allowed */
    public function __construct(
        private array $allowed,
        private string $current,
    ) {}

    /** {@inheritDoc} */
    public function isSatisfied(DateTimeImmutable $now): bool
    {
        return in_array($this->current, $this->allowed, true);
    }
}
