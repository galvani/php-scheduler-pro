# Laravel demo

A fresh Laravel 13 application (`composer create-project laravel/laravel`) with the package installed the way a
user installs it: `composer require galvani/php-scheduler-pro`, through a Composer `path` repository at `../..`.
Nothing else in the app was changed except the files listed below.

## What is in it

| File | What it shows |
| --- | --- |
| `composer.json` | the `path` repository at `../..` and `galvani/php-scheduler-pro` in `require` |
| `scheduling.yaml` | a task of the package: `hello`, every minute, `max_runtime: 30` |
| `routes/console.php` | the two commands, and `app:legacy-report` scheduled the Laravel way, as an existing app has it |
| `config/scheduling.php` | the published package config (`php artisan vendor:publish --tag=scheduling-config`) |
| `.env.example` | Redis on `16379`, the file cache, `SCHEDULING_HOST` |
| `prove.sh` | the proof below |

## Run it

```bash
docker compose run --rm demo-laravel   # from the repo root; Redis is started with it
./prove.sh                            # or on the host, against Redis at REDIS_HOST:REDIS_PORT (default 127.0.0.1:16379)
```

## Output under an AI agent

The Laravel 13 skeleton includes `laravel/pao`, which rewrites console output for AI coding agents when it
detects one (`CLAUDECODE`, `AI_AGENT`, `CURSOR_AGENT` and others in the environment) and collapses indentation on
the way. `scheduling:export` then prints YAML that does not parse. In a normal terminal, and in Docker, the output
is as shown below.

## What it proves

`prove.sh` runs `php artisan schedule:run` twice in the same minute as two servers, `server-a` and `server-b`,
against one Redis:

- the package's task runs once, on server-a; server-b skips it, and the run record says so;
- the app's own `Schedule::command()` entry also runs once: it got the package's Redis lock
  (`scheduler:external:<Laravel's mutex name>`) without a change to `routes/console.php`;
- `php artisan scheduling:export` prints that entry as YAML, ready to move into `scheduling.yaml`.

```
== schedule:run on server-a
  Running ['artisan' app:hello --from='scheduling.yaml']  DONE
  Running ['artisan' app:legacy-report]  DONE
== schedule:run on server-b, same minute
  INFO  Skipping ['artisan' app:hello --from='scheduling.yaml'] because the command already ran on another server.
  INFO  Skipping [Legacy report, scheduled in PHP] because the command already ran on another server.
== Redis: run record of hello, oldest first
started   server-a
finished  server-a
skipped   server-b ran_on_another_server
== Redis: the app entry claimed its minute under our mutex
scheduler:external:framework/schedule-5a61a570701e2f65646ce9bb8e32b79626c14096:202610091902
== scheduling:export (the app's own entries as YAML for scheduling.yaml)
tasks:
  # Legacy report, scheduled in PHP
  app-legacy-report:
    schedule: '* * * * *'
    command: 'app:legacy-report'
    max_runtime: 300
    lock_margin: 1.0
```
