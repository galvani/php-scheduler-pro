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
| `.env.example` | Redis on `16379`, the file cache |
| `prove.sh` | the proof below |

## Run it

```bash
docker compose run --rm demo-laravel   # from the repo root; Redis is started with it
./prove.sh                            # or on the host, against Redis at REDIS_HOST:REDIS_PORT (default 127.0.0.1:16379)
```

## What it proves

`prove.sh` runs `php artisan schedule:run` twice in the same minute as two servers, `server-a` and `server-b`,
against one Redis. `DEMO_SERVER` names the server in the line each command writes. It checks:

- the package's task runs once, on server-a; server-b skips it;
- the app's own `Schedule::command()` entry also runs once: it got the package's Redis lock
  (`scheduler:external:<Laravel's mutex name>`) without a change to `routes/console.php`.

```
== schedule:run on server-a
  Running ['artisan' app:legacy-report]  DONE
  Running ['artisan' app:hello --from='scheduling.yaml']  DONE
== schedule:run on server-b, same minute
  INFO  Skipping [Legacy report, scheduled in PHP] because the command already ran on another server.
  INFO  Skipping ['/usr/local/bin/php' 'artisan' app:hello --from='scheduling.yaml' > '/dev/null' 2>&1] because the command already ran on another server.
== storage/logs/hello.log (package task from scheduling.yaml)
2026-10-13T09:59:16+00:00 server-a
== storage/logs/legacy-report.log (the app's own entry in routes/console.php)
2026-10-13T09:59:16+00:00 server-a
== Redis: the app entry claimed its minute under our mutex
scheduler:external:framework/schedule-5a61a570701e2f65646ce9bb8e32b79626c14096:202610130959
== OK: each task ran once, on server-a; server-b skipped both
```
