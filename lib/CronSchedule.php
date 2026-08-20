<?php

/**
 * Minimal five-field cron expression matcher: minute hour day-of-month month
 * day-of-week, each supporting *, n, a-b, lists and /step.
 *
 * Only what the job schedules need — no @reboot, no names like MON, no
 * seconds. Anything it cannot parse throws rather than silently never firing.
 */
class CronSchedule
{
  /** @var array<int,int[]> allowed values per field */
  private $fields;

  /** @var bool both day fields restricted — cron ORs them in that case */
  private $dayOr;

  private const RANGES = [
    [0, 59],  // minute
    [0, 23],  // hour
    [1, 31],  // day of month
    [1, 12],  // month
    [0, 7],   // day of week (0 and 7 are both Sunday)
  ];

  public function __construct($expression)
  {
    $parts = preg_split('/\s+/', trim((string)$expression), -1, PREG_SPLIT_NO_EMPTY);

    if (count($parts) !== 5)
    {
      throw new InvalidArgumentException("cron expression must have 5 fields: $expression");
    }

    foreach ($parts as $i => $part)
    {
      $this->fields[$i] = $this->parseField($part, self::RANGES[$i][0], self::RANGES[$i][1]);
    }

    // Sunday is both 0 and 7; normalise so matching only has to check one.
    if (in_array(7, $this->fields[4], true) && !in_array(0, $this->fields[4], true))
    {
      $this->fields[4][] = 0;
    }

    $this->dayOr = $parts[2] !== '*' && $parts[4] !== '*';
  }

  /**
   * Does this schedule fire at the given minute?
   */
  public function matches($timestamp)
  {
    $minute = (int)date('i', $timestamp);
    $hour = (int)date('G', $timestamp);
    $dom = (int)date('j', $timestamp);
    $month = (int)date('n', $timestamp);
    $dow = (int)date('w', $timestamp);

    if (!in_array($minute, $this->fields[0], true) || !in_array($hour, $this->fields[1], true))
    {
      return false;
    }

    if (!in_array($month, $this->fields[3], true))
    {
      return false;
    }

    $domMatch = in_array($dom, $this->fields[2], true);
    $dowMatch = in_array($dow, $this->fields[4], true);

    // Standard cron quirk: when both day fields are restricted a match on
    // either one is enough; otherwise both must hold.
    return $this->dayOr ? ($domMatch || $dowMatch) : ($domMatch && $dowMatch);
  }

  /**
   * Did this schedule fire at any minute in ($after, $until]?
   *
   * This is what makes the tick interval irrelevant: a job set to 04:05 is
   * still found by a scheduler that only wakes up every half hour, and one
   * missed while the server was down is picked up on the next run.
   *
   * @param int $after zero or a timestamp; the window is capped so a long
   *                   outage cannot turn this into a huge loop
   */
  public function matchedSince($after, $until, $maxLookbackSeconds = 86400)
  {
    $start = max((int)$after + 60, $until - $maxLookbackSeconds);

    // Never run before: fire on the first tick that matches, rather than
    // replaying a day of history.
    if ((int)$after === 0)
    {
      $start = $until - 60;
    }

    for ($t = $this->floorToMinute($start); $t <= $until; $t += 60)
    {
      if ($this->matches($t))
      {
        return true;
      }
    }

    return false;
  }

  private function floorToMinute($timestamp)
  {
    return $timestamp - ($timestamp % 60);
  }

  /**
   * @return int[] every value this field allows
   */
  private function parseField($field, $min, $max)
  {
    $values = [];

    foreach (explode(',', $field) as $item)
    {
      $step = 1;

      if (strpos($item, '/') !== false)
      {
        [$item, $stepPart] = explode('/', $item, 2);
        $step = (int)$stepPart;

        if ($step < 1)
        {
          throw new InvalidArgumentException("invalid step in cron field: $field");
        }
      }

      if ($item === '*')
      {
        $from = $min;
        $to = $max;
      }
      elseif (strpos($item, '-') !== false)
      {
        [$from, $to] = array_map('intval', explode('-', $item, 2));
      }
      elseif (is_numeric($item))
      {
        $from = $to = (int)$item;
      }
      else
      {
        throw new InvalidArgumentException("cannot parse cron field: $field");
      }

      if ($from < $min || $to > $max || $from > $to)
      {
        throw new InvalidArgumentException("cron field out of range ($min-$max): $field");
      }

      for ($v = $from; $v <= $to; $v += $step)
      {
        $values[] = $v;
      }
    }

    return array_values(array_unique($values));
  }
}
