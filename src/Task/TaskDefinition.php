<?php declare(strict_types=1);

namespace Scheduling\Task;

use DateTimeZone;

final readonly class TaskDefinition
{
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
