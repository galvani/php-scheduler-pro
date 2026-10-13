<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * A run id is not 32 lower-case hex characters, e.g. in a damaged run record read back from storage.
 */
final class InvalidRunIdException extends SchedulerException {}
