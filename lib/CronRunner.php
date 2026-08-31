<?php

/**
 * Decides which jobs are due, runs them, and reports failures in the way cron
 * expects: nothing on stdout while all is well, output plus a non-zero exit
 * when something broke.
 *
 * A failing job never stops the ones after it — a stalled Erply sync must not
 * also stop the feeds from being rebuilt — but the run still ends non-zero.
 */
class CronRunner
{
  /** @var array */
  private $jobs;

  /** @var string */
  private $logDir;

  /** @var bool */
  private $verbose;

  /** @var string */
  private $stateFile;

  /** @var array<string,int> job => last successful start time */
  private $state;

  public function __construct(array $jobs, $logDir, $verbose = false)
  {
    $this->jobs = $jobs;
    $this->logDir = rtrim($logDir, '/');
    $this->verbose = (bool)$verbose;
    $this->stateFile = $this->logDir . '/cron-state.json';

    if (!is_dir($this->logDir))
    {
      mkdir($this->logDir, 0755, true);
    }

    $this->state = $this->readState();
  }

  public function listJobs()
  {
    foreach ($this->jobs as $name => $job)
    {
      $last = isset($this->state[$name]) ? date('Y-m-d H:i', $this->state[$name]) : 'never';
      printf("%-10s %-14s %-8s last run: %s\n", $name, $job['schedule'], $job['type'], $last);
    }
  }

  /**
   * @return bool false when at least one job failed
   */
  public function runDue($dryRun = false)
  {
    $now = time();
    $ok = true;

    foreach ($this->jobs as $name => $job)
    {
      $schedule = new CronSchedule($job['schedule']);
      $lastRun = isset($this->state[$name]) ? $this->state[$name] : 0;
      $lookback = isset($job['lookback']) ? (int)$job['lookback'] : 86400;

      if (!$schedule->matchedSince($lastRun, $now, $lookback))
      {
        continue;
      }

      if ($dryRun)
      {
        echo "$name is due\n";
        continue;
      }

      if (!$this->run($name, $job))
      {
        $ok = false;
      }
    }

    return $ok;
  }

  /**
   * @return bool
   */
  public function runOne($name)
  {
    if (!isset($this->jobs[$name]))
    {
      fwrite(STDERR, "unknown job: $name (have: " . implode(', ', array_keys($this->jobs)) . ")\n");

      return false;
    }

    return $this->run($name, $this->jobs[$name]);
  }

  private function run($name, array $job)
  {
    // One lock per job: a slow job delays only its own next run.
    $lock = fopen($this->logDir . '/cron-' . $name . '.lock', 'c');

    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
    {
      $this->log($name, 'previous run still going, skipped');
      $this->say("$name: previous run still going, skipped");

      return true;
    }

    // Recorded before running, not after: a job that takes longer than the
    // tick would otherwise look permanently overdue and start again and
    // again once the lock cleared.
    $this->state[$name] = time();
    $this->writeState();

    $this->log($name, 'start');
    $started = microtime(true);

    try
    {
      $output = $job['type'] === 'http'
        ? $this->runHttp($job)
        : $this->runCommand($job);
    }
    catch (RuntimeException $e)
    {
      $this->log($name, 'FAILED — ' . $e->getMessage());
      // Goes to stdout, which is what cron turns into a mail.
      echo "$name failed: " . $e->getMessage() . "\n";
      flock($lock, LOCK_UN);
      fclose($lock);

      return false;
    }

    $elapsed = sprintf('%.1fs', microtime(true) - $started);
    $this->log($name, "ok ($elapsed)");
    $this->say("$name: ok ($elapsed)" . ($output !== '' ? "\n" . $output : ''));

    flock($lock, LOCK_UN);
    fclose($lock);

    return true;
  }

  /**
   * @return string response body, trimmed for logging
   * @throws RuntimeException on transport, status or body failure
   *
   */
  private function runHttp(array $job)
  {
    $ch = curl_init($job['url']);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => isset($job['timeout']) ? (int)$job['timeout'] : 300,
      CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false)
    {
      throw new RuntimeException("request failed: $error");
    }

    $shortBody = trim(substr($body, 0, 2000));

    if ($status < 200 || $status > 299)
    {
      throw new RuntimeException("HTTP $status" . ($body !== '' ? " — $shortBody" : ''));
    }

    if (!empty($job['error']) && stripos($body, $job['error']) !== false)
    {
      throw new RuntimeException("module reported an error — $shortBody");
    }

    if (!empty($job['expect']) && stripos($body, $job['expect']) === false)
    {
      throw new RuntimeException("unexpected response — $shortBody");
    }

    return $shortBody;
  }

  /**
   * Runs the job as a separate process rather than in-process, so a fatal
   * error inside it cannot take the scheduler down with it.
   *
   * @return string combined output
   * @throws RuntimeException on a non-zero exit, or on exceeding the job's
   *                          timeout
   *
   */
  private function runCommand(array $job)
  {
    // "exec" so the shell replaces itself with the command instead of
    // forking it — otherwise proc_terminate() below only kills the shell,
    // leaving the actual command running.
    $command = 'exec ' . implode(' ', array_map('escapeshellarg', $job['command'])) . ' 2>&1';
    $timeout = isset($job['timeout']) ? (int)$job['timeout'] : 300;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process))
    {
      throw new RuntimeException('could not start: ' . $job['command'][0]);
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $timedOut = false;
    $exitCode = null;

    // try/finally rather than closing pipes after the loop: if anything in
    // here throws unexpectedly, the process — running standalone because of
    // "exec" above — must still be reaped, or it outlives this PHP process.
    try
    {
      $deadline = microtime(true) + $timeout;

      while (true)
      {
        $status = proc_get_status($process);

        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);

        if (!$status['running'])
        {
          break;
        }

        $remaining = $deadline - microtime(true);

        if ($remaining <= 0)
        {
          $timedOut = true;
          proc_terminate($process, 9); // SIGKILL by number — pcntl (which defines the constant) isn't always installed
          break;
        }

        $read = [$pipes[1], $pipes[2]];
        $write = $except = null;
        stream_select($read, $write, $except, (int)$remaining, (int)(fmod($remaining, 1) * 1e6));
      }
    }
    finally
    {
      if (proc_get_status($process)['running'])
      {
        proc_terminate($process, 9);
      }

      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($process);
    }

    $output = trim((string)$output);

    if ($timedOut)
    {
      throw new RuntimeException("timed out after {$timeout}s" . ($output !== '' ? " — $output" : ''));
    }

    if ($exitCode !== 0)
    {
      throw new RuntimeException("exited with code $exitCode" . ($output !== '' ? " — $output" : ''));
    }

    if (!empty($job['expect']) && stripos($output, $job['expect']) === false)
    {
      throw new RuntimeException("unexpected output" . ($output !== '' ? " — $output" : ''));
    }

    return $output;
  }

  private function say($message)
  {
    if ($this->verbose)
    {
      echo $message . "\n";
    }
  }

  private function log($job, $message)
  {
    file_put_contents(
      $this->logDir . '/cron.log',
      sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $job, $message),
      FILE_APPEND
    );
  }

  private function readState()
  {
    if (!is_file($this->stateFile))
    {
      return [];
    }

    $state = json_decode((string)file_get_contents($this->stateFile), true);

    return is_array($state) ? $state : [];
  }

  private function writeState()
  {
    // Written atomically: a tick interrupted mid-write must not leave the
    // schedule unreadable and every job looking due forever.
    $tmp = $this->stateFile . '.tmp';
    file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT));
    rename($tmp, $this->stateFile);
  }
}
