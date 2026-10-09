<?php declare(strict_types=1);

namespace Scheduling\Exception;

use Exception;

/**
 * Base of every checked exception this package throws, so a caller can catch the whole family at once.
 */
abstract class SchedulerException extends Exception {}
