<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * The lock storage (Redis) cannot be reached, so nobody can tell whether the lock is held.
 * Callers skip the run: running twice is worse than running late.
 */
final class LockUnavailableException extends SchedulerException {}
