<?php declare(strict_types=1);

namespace Scheduling\Task;

interface TaskSourceInterface
{
    public function load(): TaskSet;
}
