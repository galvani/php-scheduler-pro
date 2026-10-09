<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\CronExpression;

final class CronExpressionTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testKeepsAValidExpression(): void
    {
        self::assertSame('*/5 * * * *', new CronExpression('*/5 * * * *')->value);
    }

    /** @throws InvalidTaskException */
    public function testRejectsAnInvalidExpression(): void
    {
        $this->expectException(InvalidTaskException::class);

        new CronExpression('not a cron');
    }
}
