<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Scheduling\Bridge\Laravel\EventInspector;

/** Isolates EventInspector: it reads real Laravel Events built on a stubbed mutex. */
final class EventInspectorUnitTest extends TestCase
{
    public function testFullyConfiguredPlainEventHasNoNotes(): void
    {
        self::assertSame([], $this->notes($this->event()));
    }

    public function testEventWithoutOneServerOrOverlapProtectionIsNoted(): void
    {
        $notes = $this->notes(new Event($this->createStub(EventMutex::class), 'artisan job'));

        self::assertSame([
            'review: the app did not set onOneServer; the exported task always runs on one server',
            'review: the app did not set withoutOverlapping; the exported task always takes the overlap lock',
        ], $notes);
    }

    /** @throws ReflectionException */
    public function testFiltersAreNotedAsClosures(): void
    {
        $event = $this->event()->when(static fn (): bool => true);

        self::assertSame(["review: the app's conditions are closures and were not exported"], new EventInspector($event)->notes());
    }

    /** @throws ReflectionException */
    public function testSkipConditionsAreNotedButTheOverlapSkipIsNot(): void
    {
        $event = $this->event()->skip(static fn (): bool => false);

        self::assertSame(["review: the app's conditions are closures and were not exported"], new EventInspector($event)->notes());
    }

    public function testMaintenanceModeIsNoted(): void
    {
        self::assertSame(
            ['review: the app runs this in maintenance mode; the exported task does not'],
            $this->notes($this->event()->evenInMaintenanceMode()),
        );
    }

    public function testRunningWhilePausedIsNoted(): void
    {
        self::assertSame(
            ['review: the app runs this while the schedule is paused; the exported task does not'],
            $this->notes($this->event()->evenWhenPaused()),
        );
    }

    public function testBackgroundExecutionIsNoted(): void
    {
        self::assertSame(
            ['review: the app runs this in the background; the Laravel bridge runs tasks one after another'],
            $this->notes($this->event()->runInBackground()),
        );
    }

    public function testBeforeAndAfterCallbacksAreNoted(): void
    {
        $expected = ['review: before/after callbacks (also emailed output and pings) were not exported'];

        self::assertSame($expected, $this->notes($this->event()->before(static function (): void {})));
        self::assertSame($expected, $this->notes($this->event()->then(static function (): void {})));
    }

    public function testRunAsUserIsNoted(): void
    {
        self::assertSame(
            ["review: the app runs this as user www-data; the exported task runs as the scheduler's user"],
            $this->notes($this->event()->user('www-data')),
        );
    }

    public function testOverwrittenOutputFileIsNotedAndNotExported(): void
    {
        $inspectedEvent = $this->event()->sendOutputTo('/tmp/out.log');
        $inspector = new EventInspector($inspectedEvent);

        self::assertSame(
            ['review: the app overwrites /tmp/out.log on every run; the exported task has no output file because ours appends'],
            $this->notes($inspectedEvent),
        );
        self::assertNull($inspector->appendedOutputFile());
    }

    public function testAppendedOutputFileIsExportedWithoutANote(): void
    {
        $inspectedEvent = $this->event()->appendOutputTo('/tmp/out.log');
        $inspector = new EventInspector($inspectedEvent);

        self::assertSame('/tmp/out.log', $inspector->appendedOutputFile());
        self::assertSame([], $this->notes($inspectedEvent));
    }

    public function testEventWithoutOutputHasNoAppendedFile(): void
    {
        self::assertNull(new EventInspector($this->event())->appendedOutputFile());
    }

    /** @return list<string> */
    private function notes(Event $event): array
    {
        try {
            return new EventInspector($event)->notes();
        } catch (ReflectionException $e) {
            throw new LogicException('Event properties are not readable', previous: $e);
        }
    }

    private function event(): Event
    {
        return new Event($this->createStub(EventMutex::class), 'artisan job')->onOneServer()->withoutOverlapping(5);
    }
}
