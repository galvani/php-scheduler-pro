<?php declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Orchestra\Testbench\Foundation\Application;
use Scheduling\Bridge\Laravel\SchedulingServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../../../vendor/autoload.php';

// An artisan binary of an app with the package installed. The schedule is resolved once the app has booted, as a
// `Schedule::` call in routes/console.php resolves it on every artisan command.
$app = Application::create(
    resolvingCallback: static function (LaravelApplication $app): void {
        $app->booted(static fn (LaravelApplication $app): Schedule => $app->make(Schedule::class));
    },
    options: ['extra' => ['providers' => [SchedulingServiceProvider::class]]],
);
// Laravel's own console kernel and exception handler: Testbench's rethrow the exceptions Laravel's report.
$app->singleton(KernelContract::class, Kernel::class);
$app->singleton(ExceptionHandler::class, Handler::class);

exit($app->handleCommand(new ArgvInput()));
