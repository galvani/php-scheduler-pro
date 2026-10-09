<?php declare(strict_types=1);

namespace Scheduling\Exception;

/**
 * A task was added under an id that is already scheduled. Adding never replaces: a task is changed by removing it
 * and scheduling it again, so two writers can never overwrite each other silently.
 */
final class TaskConflictException extends SchedulerException {}
