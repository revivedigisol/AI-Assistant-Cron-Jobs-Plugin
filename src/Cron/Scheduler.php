<?php

namespace Enle\DailyCron\Cron;

use Enle\DailyCron\Settings;

defined('ABSPATH') || exit;

/**
 * WP-Cron events on this site:
 * - DAILY: the daily run, at the configured hour (UTC).
 * - CONTINUE: a one-off follow-up run when a run hit its time budget.
 * - SWEEP: a one-off stop-rule check soon after a purchase or booking, so
 *   the sequence stops the same hour rather than at the next daily run.
 *
 * WP-Cron on enle.org fires on page visits (no server cron), so a run can
 * start a little after its hour; the 7-day step window absorbs that.
 */
final class Scheduler
{
    public const DAILY    = 'enle_dc_daily';
    public const CONTINUE = 'enle_dc_continue';
    public const SWEEP    = 'enle_dc_sweep';

    public static function nextRunAt(int $hour, int $now): int
    {
        $today = $now - ($now % DAY_IN_SECONDS) + $hour * HOUR_IN_SECONDS;

        return $today > $now ? $today : $today + DAY_IN_SECONDS;
    }

    public static function schedule(): void
    {
        wp_clear_scheduled_hook(self::DAILY);
        wp_schedule_event(self::nextRunAt((int) Settings::get('run_hour'), time()), 'daily', self::DAILY);
    }

    /**
     * Self-healing (runs on init): re-creates the daily event if something
     * removed it, and moves it if the run hour changed.
     */
    public static function ensure(): void
    {
        $next = wp_next_scheduled(self::DAILY);

        if ($next === false || (int) gmdate('G', (int) $next) !== (int) Settings::get('run_hour')) {
            self::schedule();
        }
    }

    public static function continueSoon(): void
    {
        if (wp_next_scheduled(self::CONTINUE) === false) {
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::CONTINUE);
        }
    }

    public static function sweepSoon(): void
    {
        if (wp_next_scheduled(self::SWEEP) === false) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SWEEP);
        }
    }

    public static function clear(): void
    {
        foreach (array(self::DAILY, self::CONTINUE, self::SWEEP) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
