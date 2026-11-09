<?php

namespace Enle\DailyCron\Sequence;

use Enle\DailyCron\Domain\Lead;

defined('ABSPATH') || exit;

/**
 * Decides what a lead should get on a given run. Pure logic, no I/O.
 *
 * - Steps fall due on calendar days (UTC) counted from the day of the
 *   report, so "Day 3" goes out on that day's run whatever time the report
 *   was made.
 * - Each step can go out up to WINDOW_DAYS after it falls due. After that
 *   it is skipped: a "couple of weeks since your report" email must not
 *   arrive two months later because the cron was off.
 * - At most one email per run. When two are due at once (missed runs), the
 *   later one is sent and the earlier one skipped.
 * - Never two emails within MIN_GAP_DAYS of each other.
 */
final class StepPicker
{
    public const WINDOW_DAYS  = 7;
    public const MIN_GAP_DAYS = 2;

    /**
     * @return array{send:?string,skip:array<int,string>,complete:bool,reason:string}
     */
    public static function decide(Lead $lead, int $now): array
    {
        $day0    = self::startOfDay($lead->enrolled_at);
        $send    = null;
        $skip    = array();
        $pending = false;

        foreach (Steps::offsets() as $step => $offset) {
            if (isset($lead->sent[$step]) || in_array($step, $lead->skipped, true)) {
                continue;
            }

            $due     = $day0 + $offset * DAY_IN_SECONDS;
            $expires = $due + self::WINDOW_DAYS * DAY_IN_SECONDS;

            if ($now < $due) {
                $pending = true;
                break;
            }

            if ($now >= $expires) {
                $skip[] = $step;
                continue;
            }

            if ($send !== null) {
                $skip[] = $send;
            }

            $send = $step;
        }

        if ($send !== null && $lead->lastSentAt() > $now - self::MIN_GAP_DAYS * DAY_IN_SECONDS) {
            return array('send' => null, 'skip' => $skip, 'complete' => false, 'reason' => 'too_soon_after_last_email');
        }

        return array(
            'send'     => $send,
            'skip'     => $skip,
            'complete' => $send === null && !$pending,
            'reason'   => $send !== null ? 'due' : ($pending ? 'not_due_yet' : 'sequence_finished'),
        );
    }

    /** When the next step falls due, for the admin page. 0 when nothing is left. */
    public static function nextDueAt(Lead $lead): int
    {
        $day0 = self::startOfDay($lead->enrolled_at);

        foreach (Steps::offsets() as $step => $offset) {
            if (!isset($lead->sent[$step]) && !in_array($step, $lead->skipped, true)) {
                return $day0 + $offset * DAY_IN_SECONDS;
            }
        }

        return 0;
    }

    public static function nextStep(Lead $lead): string
    {
        foreach (array_keys(Steps::offsets()) as $step) {
            if (!isset($lead->sent[$step]) && !in_array($step, $lead->skipped, true)) {
                return $step;
            }
        }

        return '';
    }

    private static function startOfDay(int $ts): int
    {
        return $ts - ($ts % DAY_IN_SECONDS);
    }
}
