<?php declare(strict_types=1);

return [
    // The YAML file the package's tasks are read from.
    'tasks_file' => env('SCHEDULING_TASKS_FILE', base_path('scheduling.yaml')),

    // The Laravel Redis connection for the locks and the run record (phpredis, no key prefix). `scheduler` is
    // added for you as a copy of the `default` connection without its prefix.
    'redis' => env('SCHEDULING_REDIS_CONNECTION', 'scheduler'),

    // The name recorded with each run; null reads the machine's hostname when the app runs.
    'host' => env('SCHEDULING_HOST'),

    // Tasks added, disabled and removed at runtime, in a table next to the YAML file.
    'database' => [
        'enabled' => env('SCHEDULING_DATABASE', false),
        'connection' => null,
        'table' => 'scheduler_tasks',
    ],
];
