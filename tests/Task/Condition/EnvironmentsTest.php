<?php declare(strict_types=1);

namespace Scheduling\Tests\Task\Condition;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scheduling\Task\Condition\Environments;

final class EnvironmentsTest extends TestCase
{
    public function testSatisfiedWhenTheCurrentEnvironmentIsAllowed(): void
    {
        self::assertTrue(new Environments(['production', 'staging'], 'staging')->isSatisfied(new DateTimeImmutable()));
    }

    public function testNotSatisfiedOtherwise(): void
    {
        self::assertFalse(new Environments(['production'], 'local')->isSatisfied(new DateTimeImmutable()));
    }
}
