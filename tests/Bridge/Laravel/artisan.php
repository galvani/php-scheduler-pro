<?php declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Orchestra\Testbench\Foundation\Application;
use Scheduling\Tests\Bridge\Laravel\MarkerCommand;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../../vendor/autoload.php';

ConsoleApplication::starting(static function (ConsoleApplication $artisan): void {
    $artisan->resolve(MarkerCommand::class);
});

exit(Application::create()->handleCommand(new ArgvInput()));
