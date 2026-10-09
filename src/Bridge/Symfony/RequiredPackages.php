<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Scheduling\Exception\MissingPackageException;

/**
 * Refuses to wire the crunz engine when its Composer packages are missing, with the `composer require` line to
 * fix it, instead of letting the application die later on a class that cannot be loaded.
 */
final class RequiredPackages
{
    /**
     * @param array<string, string> $symbols a class or interface each package provides, mapped to the package name
     *
     * @throws MissingPackageException naming every package that is missing
     */
    public static function assertInstalled(array $symbols): void
    {
        $missing = [];
        foreach ($symbols as $symbol => $package) {
            if (!class_exists($symbol) && !interface_exists($symbol)) {
                $missing[] = $package;
            }
        }
        if ([] !== $missing) {
            $packages = implode(' ', $missing);
            throw new MissingPackageException("The crunz engine needs {$packages}: run \"composer require {$packages}\"");
        }
    }
}
