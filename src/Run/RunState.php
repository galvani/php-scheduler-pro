<?php declare(strict_types=1);

namespace Scheduling\Run;

enum RunState: string
{
    case Started = 'started';
    case Finished = 'finished';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Overran = 'overran';
}
