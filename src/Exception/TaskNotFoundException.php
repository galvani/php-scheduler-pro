<?php declare(strict_types=1);

namespace Scheduling\Exception;

/** A task was disabled or removed by an id that is not scheduled, so a mistyped id never passes for success. */
final class TaskNotFoundException extends SchedulerException {}
