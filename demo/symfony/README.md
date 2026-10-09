# Symfony demo

A minimal Symfony 8 app that runs `galvani/php-scheduler-pro` on the crunz engine. The library comes in through a Composer `path` repository at `../..`.

- `config/packages/scheduling.yaml` configures `SchedulingBundle`; the host name comes from `SCHEDULING_HOST`.
- `config/scheduling.yaml` holds one task, `hello`, which runs `app:hello` every minute (`max_runtime: 30`).
- `crunz.yml` points crunz at `tasks/`; `tasks/SchedulingTasks.php` boots the kernel and returns `CrunzTasks::schedule()`.
- `app:hello` appends a timestamped line with the host name to `var/hello.log`.
- `app:capabilities` prints the crunz engine's capability report.

## Run

```
composer install
REDIS_PORT=16379 ./prove.sh
```

`prove.sh` needs Redis on `127.0.0.1:${REDIS_PORT:-16379}`. It deletes only the `scheduler:*hello*` keys and `var/hello.log`.

## What it proves

Two servers run `vendor/bin/crunz schedule:run` in the same minute. Only the first runs the task; the second records that it skipped.

```
== app:capabilities
+---------------------+-------------+
| capability          | fulfilment  |
+---------------------+-------------+
| overlap_lock        | native      |
| one_server          | polyfilled  |
| conditions          | native      |
| run_record          | polyfilled  |
| sub_minute          | unavailable |
| missed_run_catch_up | unavailable |
+---------------------+-------------+
== schedule:run on server-a
== schedule:run on server-b, same minute
No event is due!
== var/hello.log
2026-10-09T18:19:25+00:00 server-a
== scheduler:runs:hello, oldest first
started   server-a 
finished  server-a 
skipped   server-b ran_on_another_server
```

`hello.log` holds one line. The run record shows `server-a` started and finished, and `server-b` skipped with `ran_on_another_server`. The second server's crunz reports "No event is due!" because the package's skip filter removes the task before crunz starts it.
