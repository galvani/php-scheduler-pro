<?php declare(strict_types=1);

use App\Kernel;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Symfony\Component\Dotenv\Dotenv;

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$tasks = $kernel->getContainer()->get(CrunzTasks::class);

return $tasks->schedule();
