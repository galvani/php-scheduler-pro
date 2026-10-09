# Scheduling

A framework-independent task scheduler component with its own mutex, and bridges to Laravel's
scheduler and crunz. The core depends on no framework; each bridge maps the core's interfaces onto
its engine.

```
src/
├── SchedulerInterface.php
├── Scheduler.php
├── SchedulerEngineInterface.php
├── Exception/
├── Task/
├── Lock/
├── Run/
└── Bridge/
    ├── Laravel/
    └── Crunz/
```

Checks: `composer phpstan`, `composer cs-fix`, `composer phpmd`.
