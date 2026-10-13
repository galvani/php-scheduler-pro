<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Two commands, scheduled two ways:
 * - app:hello is a task of the package, defined in scheduling.yaml.
 * - app:legacy-report is the application's own schedule entry, as an existing Laravel app has it.
 *   It keeps running from this file and gets the package's Redis lock without any change here.
 */

Artisan::command('app:hello {--from=}', function (): void {
    file_put_contents(storage_path('logs/hello.log'), now()->toIso8601String().' '.(getenv('DEMO_SERVER') ?: gethostname()).PHP_EOL, FILE_APPEND);
})->purpose('Append a line to storage/logs/hello.log');

Artisan::command('app:legacy-report', function (): void {
    file_put_contents(storage_path('logs/legacy-report.log'), now()->toIso8601String().' '.(getenv('DEMO_SERVER') ?: gethostname()).PHP_EOL, FILE_APPEND);
})->purpose('The application\'s own scheduled command');

Schedule::command('app:legacy-report')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->description('Legacy report, scheduled in PHP');

