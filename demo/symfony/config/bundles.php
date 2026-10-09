<?php declare(strict_types=1);

use Scheduling\Bridge\Symfony\SchedulingBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

return [
    FrameworkBundle::class => ['all' => true],
    SchedulingBundle::class => ['all' => true],
];
