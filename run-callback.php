#!/usr/bin/env php
<?php
/**
 * Runs one PHP callable as a cron job, after bootstrapping whatever
 * framework or CMS it lives in.
 *
 * A separate script rather than in-process, on purpose: cron.php itself
 * deliberately never bootstraps the application, so it keeps working — and
 * can report the failure — when the app is broken. Bootstrapping only
 * happens here, in a child process CronRunner already isolates from the
 * scheduler.
 *
 * The loader is whatever single file boots the app enough to make the
 * callable reachable:
 *   - PrestaShop: config/config.inc.php
 *   - WordPress:  wp-load.php
 *   - Drupal, Laravel and anything without one canonical bootstrap file:
 *     write a small shim (e.g. bin/bootstrap.php) that does whatever the
 *     framework needs — require vendor/autoload.php, boot the kernel, etc.
 *     — and pass that as the loader instead.
 *
 * Usage: run-callback.php <path/to/loader.php> <Class::method|function> [<json-encoded array of arguments>]
 */
$loader = $argv[1] ?? null;
$callback = $argv[2] ?? null;

if ($loader === null || $callback === null)
{
  fwrite(STDERR, "usage: run-callback.php <path/to/loader.php> <Class::method|function> [<json args>]\n");
  exit(1);
}

if (!is_file($loader))
{
  fwrite(STDERR, "loader not found: $loader\n");
  exit(1);
}

require $loader;

if (!is_callable($callback))
{
  fwrite(STDERR, "not callable: $callback\n");
  exit(1);
}

$args = [];

if (isset($argv[3]))
{
  $args = json_decode($argv[3], true);

  if (json_last_error() !== JSON_ERROR_NONE)
  {
    fwrite(STDERR, 'invalid json args: ' . json_last_error_msg() . "\n");
    exit(1);
  }
}

$result = call_user_func_array($callback, (array)$args);

// Common convention across frameworks: false (or a non-empty string, for
// calls that return an error message instead of a bool) means failure,
// anything else means success. Not every callable fits that, but it covers
// simple maintenance/cron-style methods.
if ($result === false || (is_string($result) && $result !== ''))
{
  fwrite(STDERR, ($result === false ? "$callback returned false\n" : $result . "\n"));
  exit(1);
}

if (is_scalar($result) && $result !== '' && $result !== true)
{
  echo $result . "\n";
}
