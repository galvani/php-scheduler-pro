<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use Crunz\Schedule;
use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Symfony\RequiredPackages;
use Scheduling\Exception\MissingPackageException;
use Symfony\Component\Lock\PersistingStoreInterface;

/** The crunz engine is refused, with the packages to install, when its optional packages are missing. */
final class RequiredPackagesTest extends TestCase
{
    /** @throws MissingPackageException */
    public function testInstalledPackagesPass(): void
    {
        RequiredPackages::assertInstalled([Schedule::class => 'crunzphp/crunz', PersistingStoreInterface::class => 'symfony/lock']);

        $this->addToAssertionCount(1);
    }

    /** @throws MissingPackageException */
    public function testMissingPackagesAreNamedWithTheCommandToInstallThem(): void
    {
        $this->expectException(MissingPackageException::class);
        $this->expectExceptionMessage('The crunz engine needs vendor/absent other/absent: run "composer require vendor/absent other/absent"');

        RequiredPackages::assertInstalled([
            'Vendor\\Absent\\Schedule' => 'vendor/absent',
            Schedule::class => 'crunzphp/crunz',
            'Other\\Absent\\StoreInterface' => 'other/absent',
        ]);
    }
}
