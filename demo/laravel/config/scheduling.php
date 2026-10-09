<?php declare(strict_types=1);

return [
    'tasks_file' => env('SCHEDULING_TASKS_FILE', base_path('scheduling.yaml')),
    'redis' => env('SCHEDULING_REDIS_CONNECTION', 'scheduler'),
    'host' => env('SCHEDULING_HOST', gethostname() ?: 'localhost'),
    'database' => [
        'enabled' => env('SCHEDULING_DATABASE', false),
        'connection' => null,
        'table' => 'scheduler_tasks',
    ],
];
