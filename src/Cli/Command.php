<?php

namespace Enle\DailyCron\Cli;

use Enle\DailyCron\Cron\Runner;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Plugin;
use Enle\DailyCron\Sequence\StepPicker;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Settings;
use WP_CLI;

defined('ABSPATH') || exit;

/**
 * Idea-to-Market follow-ups.
 *
 * ## EXAMPLES
 *
 *     wp enle-dc status
 *     wp enle-dc run --force
 *     wp enle-dc preview 12 --step=day7
 *     wp enle-dc test-lead --email=you@enle.org --days-ago=3
 */
final class Command
{
    /**
     * Runs the daily job now.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Run even when sending is switched off in the settings.
     */
    public function run(array $args, array $assoc): void
    {
        $summary = Plugin::runner()->run(!empty($assoc['force']));

        WP_CLI::line((string) wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (($summary['status'] ?? '') === 'ok' && empty($summary['failed'])) {
            WP_CLI::success('Run finished.');
        } elseif (($summary['status'] ?? '') === 'disabled') {
            WP_CLI::warning('Sending is switched off. Use --force to run anyway.');
        } elseif (($summary['status'] ?? '') === 'locked') {
            WP_CLI::warning('Another run is in progress.');
        } else {
            WP_CLI::warning('Run finished with problems (see "failed").');
        }
    }

    /**
     * Shows the settings, the last run and the active leads.
     */
    public function status(): void
    {
        $s = Settings::all();
        WP_CLI::line(sprintf('Sending: %s. Mode: %s. Run hour: %02d:00 UTC.', $s['enabled'] ? 'on' : 'off', $s['mode'], $s['run_hour']));
        WP_CLI::line('Last run: ' . wp_json_encode(get_option(Runner::LAST_RUN_OPTION, array())));

        $rows = array();
        foreach (Plugin::store()->recent(100) as $lead) {
            $rows[] = array(
                'id'       => $lead->id,
                'email'    => $lead->email,
                'reported' => gmdate('Y-m-d', $lead->enrolled_at),
                'status'   => $lead->status . ($lead->stop_reason !== '' ? ' (' . $lead->stop_reason . ')' : ''),
                'sent'     => implode(',', array_keys($lead->sent)),
                'next'     => $lead->isActive() ? StepPicker::nextStep($lead) : '',
            );
        }

        $rows === array() ? WP_CLI::line('No leads.') : WP_CLI\Utils\format_items('table', $rows, array('id', 'email', 'reported', 'status', 'sent', 'next'));
    }

    /**
     * Writes a lead's email with Claude and prints it. Nothing is sent or saved.
     *
     * ## OPTIONS
     *
     * <lead-id>
     * : The lead.
     *
     * [--step=<step>]
     * : day3, day7 or day14. Default: the lead's next step.
     */
    public function preview(array $args, array $assoc): void
    {
        $lead = Plugin::store()->find((int) $args[0]);

        if ($lead === null) {
            WP_CLI::error('No such lead.');
        }

        $step = isset($assoc['step']) ? (string) $assoc['step'] : StepPicker::nextStep($lead);

        if (!Steps::exists($step)) {
            WP_CLI::error('Unknown step. Use day3, day7 or day14.');
        }

        $draft = Plugin::writer()->write($lead, $step);

        if (is_wp_error($draft)) {
            WP_CLI::error($draft->get_error_message());
        }

        WP_CLI::line('Subject: ' . $draft['subject']);
        WP_CLI::line('');
        WP_CLI::line($draft['body']);
    }

    /**
     * Adds a test lead with a sample report (Mariam's beaded bags), dated in the past.
     *
     * ## OPTIONS
     *
     * --email=<email>
     * : Where its emails go in live mode. Use a team inbox.
     *
     * [--name=<name>]
     * : Default: "Mariam Test".
     *
     * [--days-ago=<days>]
     * : How long ago the "report" was made. Default: 3 (Day 3 is due).
     */
    public function test_lead(array $args, array $assoc): void
    {
        $email = sanitize_email((string) ($assoc['email'] ?? ''));

        if (!is_email($email)) {
            WP_CLI::error('Give a valid --email.');
        }

        $lead = new Lead(
            id: 0,
            email: $email,
            name: sanitize_text_field((string) ($assoc['name'] ?? 'Mariam Test')),
            crm_contact_id: 0,
            report_title: 'Idea Validation: Handmade Beaded Bags (TEST)',
            report_url: '',
            report: SampleReport::sections(),
            enrolled_at: time() - max(0, (int) ($assoc['days-ago'] ?? 3)) * DAY_IN_SECONDS,
        );

        $id = Plugin::store()->save($lead);
        Plugin::store()->log($id, '', 'enrolled', 'Test lead added with WP-CLI.');

        WP_CLI::success(sprintf('Test lead %d added. Preview it with: wp enle-dc preview %d', $id, $id));
    }
}
