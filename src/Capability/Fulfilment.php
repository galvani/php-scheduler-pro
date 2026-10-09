<?php declare(strict_types=1);

namespace Scheduling\Capability;

enum Fulfilment: string
{
    case Native = 'native';
    case Polyfilled = 'polyfilled';
    case Unavailable = 'unavailable';
}
