<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * A write was refused: the task id belongs to a read-only source, or no writable source is configured.
 */
final class TaskNotWritableException extends SchedulerException {}
