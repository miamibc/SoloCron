#!/usr/bin/env php
<?php
/**
 * Scheduler for the background jobs.
 *
 * This hosting allows only two cron entries, and the jobs do not share a
 * schedule, so cron drives this script on a fixed tick and the schedules live
 * here instead:
 *
 *   * * * * * php path/to/cron.php
 *
 * Any tick works — every minute, every 10, every 30. A job is due when its
 * schedule matched at any point since it last ran, not when it matches the
 * exact minute of the tick, so a coarse tick still fires "5 4 * * *" and a
 * missed tick (server down, overrun) is caught up on the next one.
 *
 * Usage:
 *   cron.php                 run everything that is due (what cron calls)
 *   cron.php --list          show schedules and last run times
 *   cron.php --run=<job>     run one job now, ignoring its schedule
 *   cron.php --dry-run       report what is due without running it
 *   cron.php -v              print results, not just failures
 *
 * Can live in Prestashop's `bin/` directory because that is the only directory
 * Apache denies by default (bin/.htaccess is "Require all denied").
 */

$phpBin = getenv('PHP_BIN') ?: PHP_BINARY;
$logDir = getenv('LOG_DIR') ?: dirname(__FILE__) . '/var';

/**
 * type     http    — fetch a URL; the module does the work
 *          command — run a local program
 * expect   output/body must contain this to count as success (optional)
 * error    body containing this is a failure even on HTTP 200, http only (optional)
 * timeout  seconds before the job is killed and counted as failed (optional,
 *          default 300) — request timeout for http, process time limit for
 *          command
 * lookback seconds to search back for a missed schedule match (optional,
 *          default 86400) — raise it for a schedule finer than the crontab
 *          tick, or for a job that can go a long time between ticks
 */
$jobs = [
  'url-with-expected-text' => [
    'schedule' => '0,30 * * * *',
    'type' => 'http',
    'url' => 'https://blackcrystal.net/en/contact/',
    'expect' => 'Show what you can. Learn what you don\'t.', // check for expected text in the body
    'timeout' => 900,                                        // uses curl timeout
  ],
  'success-with-text-ok' => [
    'schedule' => '*/5 * * * *',
    'type' => 'command',
    'command' => ['echo', 'ok'],
    'expect' => "ok",                                        // check for expected text in the command output
    'timeout' => 900,                                        // proc_close used to kill process, if it runs longer
    'lookback' => 1800,                                      // crontab tick is coarser than every 5 minutes
  ],
  'always-fail' => [
    'schedule' => '*/5 * * * *',
    'type' => 'command',
    'command' => ['false'],                                  // always fail
  ],
];

require __DIR__ . '/lib/CronSchedule.php';
require __DIR__ . '/lib/CronRunner.php';

$options = getopt('v', ['list', 'dry-run', 'run:', 'help']);

if (isset($options['help']))
{
  fwrite(STDOUT, "usage: cron.php [-v] [--list] [--dry-run] [--run=<job>]\n");
  exit(0);
}

$runner = new CronRunner($jobs, $logDir, isset($options['v']));

if (isset($options['list']))
{
  $runner->listJobs();
  exit(0);
}

if (isset($options['run']))
{
  exit($runner->runOne($options['run']) ? 0 : 1);
}

exit($runner->runDue(isset($options['dry-run'])) ? 0 : 1);
