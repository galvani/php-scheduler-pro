<?php declare(strict_types=1);

namespace Scheduling\Tests\Lock;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Lock\LockKey;
use Scheduling\Task\TaskId;

/** Isolates the key naming scheme of LockKey, which every mutex adapter depends on. */
final class LockKeyTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testTaskKeyIsNamespacedById(): void
    {
        self::assertSame('scheduler:task:report', LockKey::forTask(new TaskId('report'))->value);
    }

    /** @throws InvalidTaskException */
    public function testTaskMinuteKeyIsFormattedInUtc(): void
    {
        $prague = new DateTimeImmutable('2026-03-04 15:30:45', new DateTimeZone('Europe/Prague'));

        self::assertSame('scheduler:task:report:202603041430', LockKey::forTaskAt(new TaskId('report'), $prague)->value);
    }

    /** @throws InvalidTaskException */
    public function testTaskMinuteKeyAcceptsMutableDateTime(): void
    {
        $time = new DateTime('2026-12-31 23:59:59', new DateTimeZone('UTC'));

        self::assertSame('scheduler:task:report:202612312359', LockKey::forTaskAt(new TaskId('report'), $time)->value);
    }

    public function testExternalKeyIsNamespacedByName(): void
    {
        self::assertSame('scheduler:external:framework/schedule-abc', LockKey::forExternal('framework/schedule-abc')->value);
    }

    public function testExternalMinuteKeyIsFormattedInUtc(): void
    {
        $tokyo = new DateTimeImmutable('2026-03-05 00:05:00', new DateTimeZone('Asia/Tokyo'));

        self::assertSame('scheduler:external:job:202603041505', LockKey::forExternalAt('job', $tokyo)->value);
    }
}
