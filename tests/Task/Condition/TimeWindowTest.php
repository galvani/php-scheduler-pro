<?php declare(strict_types=1);

namespace Scheduling\Tests\Task\Condition;

use DateMalformedStringException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\Condition\TimeWindow;

final class TimeWindowTest extends TestCase
{
    /**
     * @throws DateMalformedStringException
     * @throws InvalidTaskException
     */
    #[DataProvider('times')]
    public function testSatisfiedOnlyInsideTheWindow(string $from, string $to, string $now, bool $expected): void
    {
        self::assertSame($expected, new TimeWindow($from, $to)->isSatisfied(new DateTimeImmutable("2026-01-01 {$now}")));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function times(): iterable
    {
        yield 'inside' => ['09:00', '17:00', '12:00', true];
        yield 'from is inclusive' => ['09:00', '17:00', '09:00', true];
        yield 'to is inclusive' => ['09:00', '17:00', '17:00', true];
        yield 'before' => ['09:00', '17:00', '08:59', false];
        yield 'after' => ['09:00', '17:00', '17:01', false];
        yield 'crossing midnight, evening' => ['22:00', '06:00', '23:30', true];
        yield 'crossing midnight, morning' => ['22:00', '06:00', '05:59', true];
        yield 'crossing midnight, outside' => ['22:00', '06:00', '12:00', false];
        yield 'crossing midnight, from is inclusive' => ['22:00', '06:00', '22:00', true];
        yield 'crossing midnight, to is inclusive' => ['22:00', '06:00', '06:00', true];
    }

    /** @throws InvalidTaskException */
    #[DataProvider('invalidTimes')]
    public function testRejectsAMalformedTime(string $from, string $to): void
    {
        $this->expectException(InvalidTaskException::class);

        new TimeWindow($from, $to);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTimes(): iterable
    {
        yield 'bad from' => ['25:00', '06:00'];
        yield 'bad to' => ['22:00', '6:00'];
        yield 'minutes out of range' => ['22:60', '06:00'];
        yield 'text' => ['noon', '06:00'];
        yield 'trailing newline' => ["22:00\n", '06:00'];
    }
}
