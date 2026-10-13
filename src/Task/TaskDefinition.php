<?php declare(strict_types=1);

namespace Scheduling\Task;

use DateTimeZone;

/**
 * One scheduled task: what runs, when, in which timezone and under which lock.
 * An immutable value object, read from a YAML entry (see TaskDefinitionMapper).
 */
final readonly class TaskDefinition
{
    /**
     * @param TaskId         $id       unique in the task list
     * @param CronExpression $schedule when the engine considers the task due
     * @param Command        $command  what to run
     * @param DateTimeZone   $timezone the zone the schedule is evaluated in
     * @param LockPolicy     $lock     runtime limit and lock lifetime
     * @param bool           $enabled  false keeps the task but stops it running
     * @param AdapterOptions $options  per-engine settings, passed through
     */
    public function __construct(
        public TaskId $id,
        public CronExpression $schedule,
        public Command $command,
        public DateTimeZone $timezone,
        public LockPolicy $lock,
        public bool $enabled = true,
        public AdapterOptions $options = new AdapterOptions(),
    ) {}
}
