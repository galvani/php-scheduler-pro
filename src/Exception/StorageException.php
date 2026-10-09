<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * Storage for tasks or run history (database, Redis) failed to read or write, or returned data that cannot be decoded.
 */
final class StorageException extends SchedulerException {}
