<?php declare(strict_types=1);

namespace Scheduling\Tests\Capability;

use PHPUnit\Framework\TestCase;
use Scheduling\Capability\Capability;
use Scheduling\Capability\CapabilityReport;
use Scheduling\Capability\Fulfilment;

/** Isolates how CapabilityReport answers for declared and for undeclared capabilities. */
final class CapabilityReportTest extends TestCase
{
    public function testDeclaredCapabilityReturnsItsFulfilment(): void
    {
        $report = new CapabilityReport([
            Capability::OverlapLock->value => Fulfilment::Native,
            Capability::RunRecord->value => Fulfilment::Polyfilled,
        ]);

        self::assertSame(Fulfilment::Native, $report->of(Capability::OverlapLock));
        self::assertSame(Fulfilment::Polyfilled, $report->of(Capability::RunRecord));
    }

    public function testMissingCapabilityIsUnavailable(): void
    {
        $report = new CapabilityReport([Capability::OverlapLock->value => Fulfilment::Native]);

        self::assertSame(Fulfilment::Unavailable, $report->of(Capability::SubMinute));
    }

    public function testEmptyReportMakesEveryCapabilityUnavailable(): void
    {
        $report = new CapabilityReport([]);

        foreach (Capability::cases() as $capability) {
            self::assertSame(Fulfilment::Unavailable, $report->of($capability));
        }
    }
}
