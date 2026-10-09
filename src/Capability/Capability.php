<?php declare(strict_types=1);

namespace Scheduling\Capability;

enum Capability: string
{
    case OverlapLock = 'overlap_lock';
    case OneServer = 'one_server';
    case Conditions = 'conditions';
    case RunRecord = 'run_record';
    case SubMinute = 'sub_minute';
    case MissedRunCatchUp = 'missed_run_catch_up';
}
