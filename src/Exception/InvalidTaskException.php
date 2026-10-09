<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * A task definition breaks a rule: a bad id, cron expression, time, timezone or lock value, or a malformed row.
 * When a source reads it, it becomes a TaskProblem instead of reaching the caller.
 */
final class InvalidTaskException extends SchedulerException {}
