# SoloCron - CRON scheduler

This is a small, dependency-free PHP scheduler for background jobs. It is meant for shared hosting that only allows
one or two crontab entries (hello Zone.ee) cron drives the script on a fixed tick, and the actual per-job schedules
(standard five-field CRON expressions) live inside `cron.php` itself.

## Install

One crontab line:

```
* * * * * php /path/to/cron.php
```

Any tick works — every minute, every 10, every 30. A job is due when its schedule matched at any point **since it last
ran**, not when it matches the exact minute of the tick, so a coarse tick still fires a job scheduled for
`5 4 * * *`, and a tick missed while the server was down is caught up on the next one. How far back it is willing to
look for a missed match is the job's `lookback` (see below) — a job can only ever run *late* against its schedule, never early.

## Jobs

Jobs are defined in the `$jobs` array at the top of `cron.php`:

```php
$jobs = [
  'url-with-expected-text' => [
    'schedule' => '0,30 * * * *',
    'type'     => 'http',
    'url'      => 'https://blackcrystal.net/en/contact/',
    'expect'   => 'Show what you can. Learn what you don\'t.', // check for expected text in the body
    'timeout'  => 900,                                         // uses curl timeout
  ],
  'success-with-text-ok' => [
    'schedule' => '*/5 * * * *',
    'type'     => 'command',
    'command'  => ['echo', 'ok'],
    'expect'   => "ok",                                        // check for expected text in the command output
    'timeout'  => 900,                                         // proc_close used to kill process, if it runs longer
    'lookback' => 1800,                                        // crontab tick is coarser than every 5 minutes
  ],
  'always-fail' => [
    'schedule' => '*/5 * * * *',
    'type'     => 'command',
    'command'  => ['false'],                                   // always fail
  ],
];
```

These are placeholder examples — replace them with real jobs for your project.

Order in the `$jobs` array matters when several are due in the same tick: they run top to bottom.

Two job types are supported:

- **`http`** — fetches a URL; whatever handles that URL does the actual work. Optional `expect` (body must contain this
  string to count as success) and `error` (body containing this string is a failure even on HTTP 200) keys let you detect 
  application-level failures, not just transport errors.
- **`command`** — runs a local program as a separate process, so a fatal error inside it cannot take the scheduler down
  with it. Optional `expect` (output must contain this string to count as success) works the same as for `http`
  jobs.

Both job types accept `timeout` (seconds, default `300`): for `http` it is the request timeout; for `command` it is the
most the process is allowed to run before it is killed and the job counted as failed.

Every job also accepts an optional `lookback` (seconds, default `86400`): how far back from now the runner is willing to
search for a missed schedule match. Raise it for a job whose schedule is finer than the crontab tick (or that can go a
long time between ticks) so it still fires after a longer gap instead of the match aging out; lower it to tighten how
late a job is allowed to run before a missed slot is given up on rather than run stale.

Edit the `$jobs` array to change a schedule or add a job.

## Output and failures

Silent while everything is fine — cron only mails when a job writes output or the run exits non-zero, and both happen
only on failure. A failing job does not stop the ones after it; the run still ends non-zero.

Failures detected: connection and timeout errors, non-2xx HTTP status, a body matching the job's `error` string, output
missing its `expect` string, a non-zero exit code from a local command, and a local command that runs past its
`timeout`.

## Calling into a framework

`cron.php` never bootstraps an application itself — a broken app should not be able to take the scheduler down with
it, and the scheduler needs to keep running to report that failure. `run-callback.php` is the way to call into one
anyway: it boots the app from a given loader file, then calls one `Class::method` or plain function in it.

```
run-callback.php <path/to/loader.php> <Class::method|function> [<json-encoded array of arguments>]
```

Use it as the `command` of a job:

```php
'nightly-cleanup' => [
  'schedule' => '0 3 * * *',
  'type'     => 'command',
  'command'  => [$phpBin, __DIR__ . '/run-callback.php', '/path/to/loader.php', 'YourClass::cronMethod'],
  'timeout'  => 900,
],
```

The loader is whatever single file boots the app enough to make the callable reachable:

- **PrestaShop** — `config/config.inc.php`
- **WordPress** — `wp-load.php`
- **Drupal, Laravel** and anything else without one canonical bootstrap file — write a small shim (e.g.
  `bin/bootstrap.php`) that does whatever the framework needs (`require vendor/autoload.php`, boot the kernel, etc.)
  and pass that as the loader instead.

The callable can be a static method (`Class::method`) or a plain function — PHP resolves both from the same string,
so `run-callback.php` does not care which. Arguments are an optional JSON-encoded array, passed to the callable as
positional parameters, e.g. `'[5, "some string"]'`.

Success or failure follows the convention most frameworks already use for maintenance-style methods: returning
`false`, or a non-empty string (an error message), counts as failure and exits `1`; anything else counts as success.
A callable that does not fit that — code that must throw to signal failure, or that has no meaningful return value —
still works, since an uncaught exception already exits non-zero on its own.

## By hand

```
cron.php --list          schedules and last run times
cron.php --dry-run       what is due, without running it
cron.php --run=<job>     run one job now, ignoring its schedule
cron.php -v              print results, not just failures
cron.php --help          usage
```

`BASE_URL` overrides the base URL used to build HTTP job URLs (defaults to `https://example.com`); `PHP_BIN` overrides 
the PHP binary used for local `command` jobs, which matters when the `php` in cron's `PATH` is the wrong version.

## Files

| Path                       |                                                             |
|----------------------------|-------------------------------------------------------------|
| `cron.php`                 | job definitions and CLI entry point                         |
| `lib/CronSchedule.php`     | cron expression parsing and matching                        |
| `lib/CronRunner.php`       | running jobs, locking, state, error reporting               |
| `lib/index.php`            | redirects requests away from `lib/` if it is web-accessible |
| `run-callback.php`         | runs one `Class::method`/function as a `command` job, after requiring a given loader file — the way to call into WordPress, Laravel, Drupal, PrestaShop, etc. without `cron.php` itself bootstrapping any of them |
| `var/logs/cron.log`        | history of every run                                        |
| `var/logs/cron-state.json` | when each job last ran                                      |

## Notes

- Each job has its own lock file: a slow job delays only its own next run, and a run that is still going is skipped
  rather than started twice.
- A job's run time is recorded before it runs, not after — otherwise a job that takes longer than the tick would look
  permanently overdue and restart on every subsequent tick.
- `CronSchedule` implements a minimal five-field cron matcher (minute, hour, day-of-month, month, day-of-week) with `*`,
  single values, ranges, lists and `/step` — no `@reboot`, no month/day names, no seconds. An expression it cannot parse 
  throws rather than silently never firing.
