<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * An engine was asked for whose Composer package is not installed. The engines are optional dependencies
 * (`suggest` in composer.json), so the package installs without them; the message names what to require.
 */
final class MissingPackageException extends SchedulerException {}
