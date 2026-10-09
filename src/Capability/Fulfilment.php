<?php declare(strict_types=1);

namespace Scheduling\Capability;

/** How an engine fulfils one Capability. */
enum Fulfilment: string
{
    /** The engine does it with its own mechanism; the package may only supply the storage, such as our lock. */
    case Native = 'native';

    /** The engine cannot do it; the package adds it on top of the engine's hooks, with the same behaviour. */
    case Polyfilled = 'polyfilled';

    /**
     * Neither the engine nor the package can do it on this engine. Scheduler::registerWith() refuses a
     * task that needs it and reports a TaskProblem, instead of letting the engine ignore it silently.
     */
    case Unavailable = 'unavailable';
}
