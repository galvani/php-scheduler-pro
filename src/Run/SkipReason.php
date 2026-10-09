<?php declare(strict_types=1);

namespace Scheduling\Run;

enum SkipReason: string
{
    case StillRunning = 'still_running';
    case RanOnAnotherServer = 'ran_on_another_server';
}
