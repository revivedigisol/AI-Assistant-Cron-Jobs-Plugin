<?php

namespace Enle\DailyCron\Alerts;

use Enle\DailyCron\Contracts\Mailer;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Settings;

defined('ABSPATH') || exit;

/**
 * Emails to Enle's team (Settings alert_email):
 * - after a run, only when something went wrong (failed emails, or the
 *   previous run was too long ago);
 * - straight away when a lead buys or books, so a person takes over.
 */
final class Alerter
{
    /** A gap longer than this between runs means the cron isn't firing. */
    public const MISSED_AFTER_HOURS = 36;

    public function __construct(private Mailer $mailer)
    {
    }

    /** @param array<string,mixed> $summary From Runner::run(). */
    public function runSummary(array $summary): bool
    {
        $failed = (array) ($summary['failed'] ?? array());
        $gap    = (float) ($summary['hours_since_previous_run'] ?? 0);

        if ($failed === array() && $gap <= self::MISSED_AFTER_HOURS) {
            return false;
        }

        $lines = array();

        if ($gap > self::MISSED_AFTER_HOURS) {
            $lines[] = sprintf('The previous run was %d hours earlier, so at least one daily run was missed. WP-Cron only runs when the site gets visits; check that DISABLE_WP_CRON is not set in wp-config.php and that the site can reach its own wp-cron.php.', (int) $gap);
        }

        foreach ($failed as $item) {
            $lines[] = sprintf('%s <%s>, %s: %s', $item['name'] ?: '(no name)', $item['email'], $item['step'], $item['error']);
        }

        $lines[] = '';
        $lines[] = sprintf('Sent: %d. Stopped: %d. Mode: %s.', count((array) ($summary['sent'] ?? array())), count((array) ($summary['stopped'] ?? array())), $summary['mode'] ?? '');
        $lines[] = 'Leads whose email failed stay in the sequence; the next daily run tries again while the email is still due.';
        $lines[] = 'Details: ' . self::adminUrl();

        $count = count($failed) + ($gap > self::MISSED_AFTER_HOURS ? 1 : 0);

        return $this->send(sprintf('[Enle follow-ups] %d problem%s in the daily run', $count, $count === 1 ? '' : 's'), $lines);
    }

    public function teamAction(Lead $lead, string $reason): bool
    {
        $who = trim($lead->name) !== '' ? $lead->name : $lead->email;

        $lines = array(
            sprintf('%s %s, so their Idea-to-Market follow-up emails have stopped.', $who, StopRules::describe($reason)),
            '',
            'Email: ' . $lead->email,
            'Report: ' . ($lead->report_title !== '' ? $lead->report_title : '(untitled)') . ($lead->report_url !== '' ? ' ' . $lead->report_url : ''),
            'Emails already sent: ' . ($lead->sent === array() ? 'none' : implode(', ', array_keys($lead->sent))),
            '',
            $reason === StopRules::PURCHASED ? 'Next: start Market Validation onboarding.' : 'Next: prepare for the consultation.',
            'Details: ' . self::adminUrl(),
        );

        return $this->send(sprintf('[Enle follow-ups] Action needed: %s %s', $who, StopRules::describe($reason)), $lines);
    }

    /** @param array<int,string> $lines */
    private function send(string $subject, array $lines): bool
    {
        $html = '<p>' . implode('<br>', array_map('esc_html', $lines)) . '</p>';

        return $this->mailer->send((string) Settings::get('alert_email'), $subject, $html) === true;
    }

    private static function adminUrl(): string
    {
        return admin_url('admin.php?page=enle-daily-cron');
    }
}
