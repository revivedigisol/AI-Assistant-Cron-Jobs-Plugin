<?php

namespace Enle\DailyCron\Sequence;

use Enle\DailyCron\Cli\SampleReport;
use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Contracts\Mailer;
use Enle\DailyCron\Cron\Runner;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Email\Renderer;
use Enle\DailyCron\Email\Writer;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * "Test the sequence" on the admin page: sends the Day 3, 7 and 14 emails to
 * the given addresses straight away, so the team can read the whole sequence
 * without waiting 14 days.
 *
 * Test sends never create or change a lead and ignore the on/off switch and
 * the mode. Each email is its own WP-Cron event (Claude takes ~10 s per
 * email), so the admin request returns at once and no single request runs
 * long. They are logged with lead 0.
 */
final class TestSend
{
    public const HOOK = 'enle_dc_test_send';

    /** Most addresses per test run (each one costs three Claude calls). */
    public const MAX_RECIPIENTS = 5;

    /** "Report to use" value for the built-in sample report. */
    public const SAMPLE = 0;

    public function __construct(private Writer $writer, private Mailer $mailer, private LeadStore $store)
    {
    }

    /**
     * Splits a comma-, semicolon- or newline-separated list into unique valid
     * addresses (at most MAX_RECIPIENTS) and the entries that weren't valid.
     *
     * @return array{valid:array<int,string>,invalid:array<int,string>,dropped:int}
     */
    public static function parseEmails(string $raw): array
    {
        $valid   = array();
        $invalid = array();

        foreach (preg_split('/[\s,;]+/', $raw) ?: array() as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $email = sanitize_email($entry);

            if (!is_email($email)) {
                $invalid[] = $entry;
            } elseif (!in_array(strtolower($email), array_map('strtolower', $valid), true)) {
                $valid[] = $email;
            }
        }

        return array(
            'valid'   => array_slice($valid, 0, self::MAX_RECIPIENTS),
            'invalid' => $invalid,
            'dropped' => max(0, count($valid) - self::MAX_RECIPIENTS),
        );
    }

    /**
     * Schedules every step for every address. All Day 3 emails are due first,
     * then Day 7, then Day 14, so they arrive in order.
     *
     * @param array<int,string> $emails
     * @return int Emails queued.
     */
    public static function queue(array $emails, string $name, int $source_lead_id, int $now): int
    {
        $batch  = substr(md5(uniqid('', true)), 0, 8); // Keeps repeat tests from being merged as duplicates.
        $queued = 0;

        foreach (array_keys(Steps::offsets()) as $i => $step) {
            foreach ($emails as $email) {
                if (wp_schedule_single_event($now + $i, self::HOOK, array($email, $step, $name, $source_lead_id, $batch))) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * Writes one step with Claude and sends it to $email. Runs from WP-Cron.
     */
    public function send(string $email, string $step, string $name, int $source_lead_id): true|WP_Error
    {
        $lead  = $this->lead($email, $name, $source_lead_id);
        $draft = $this->writer->write($lead, $step);

        if (is_wp_error($draft)) {
            $this->store->log(0, $step, 'test_failed', 'To ' . $email . ': ' . $draft->get_error_message());

            return $draft;
        }

        // No working unsubscribe link: there is no lead behind a test email.
        $html    = Renderer::html($draft['body'], home_url('/'));
        $subject = sprintf('[Test %s] %s', self::dayLabel($step), $draft['subject']);
        $result  = $this->mailer->send($email, $subject, $html, Runner::senderHeaders());

        if ($result !== true) {
            $error = is_wp_error($result) ? $result : new WP_Error('enle_dc_mail_failed', 'Sending failed.');
            $this->store->log(0, $step, 'test_failed', 'To ' . $email . ': ' . $error->get_error_message());

            return $error;
        }

        $this->store->log(0, $step, 'test_sent', sprintf('To %s (report: %s)', $email, $lead->report_title), $draft['subject'], $draft['body']);

        return true;
    }

    /**
     * The stand-in lead Claude writes for: the chosen lead's report, or the
     * sample one. $name overrides the greeting name; when empty, a real
     * lead's own name is used, and the sample gets "Hi there,".
     */
    public function lead(string $email, string $name, int $source_lead_id): Lead
    {
        $source = $source_lead_id !== self::SAMPLE ? $this->store->find($source_lead_id) : null;

        return new Lead(
            id: 0,
            email: $email,
            name: $name !== '' ? $name : ($source !== null ? $source->name : ''),
            crm_contact_id: 0,
            report_title: $source !== null ? $source->report_title : 'Sample: Handmade Beaded Bags',
            report_url: '',
            report: $source !== null ? $source->report : SampleReport::sections(),
            enrolled_at: time(),
        );
    }

    private static function dayLabel(string $step): string
    {
        return 'Day ' . Steps::offsets()[$step];
    }
}
