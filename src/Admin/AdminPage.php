<?php

namespace Enle\DailyCron\Admin;

use Enle\DailyCron\Alerts\Alerter;
use Enle\DailyCron\Cron\Runner;
use Enle\DailyCron\Cron\Scheduler;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Email\ClaudeClient;
use Enle\DailyCron\Plugin;
use Enle\DailyCron\Sequence\StepPicker;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Settings;

defined('ABSPATH') || exit;

/**
 * Settings → Idea follow-ups. Status, settings, leads and the send log.
 * Every action goes through admin-post.php with a capability check and a
 * nonce; all output is escaped.
 */
final class AdminPage
{
    public const SLUG       = 'enle-daily-cron';
    public const CAPABILITY = 'manage_options';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page(
                __('Idea follow-ups', 'enle-daily-cron'),
                __('Idea follow-ups', 'enle-daily-cron'),
                self::CAPABILITY,
                self::SLUG,
                array(self::class, 'render')
            );
        });

        add_action('admin_post_enle_dc_save', array(self::class, 'handleSave'));
        add_action('admin_post_enle_dc_run', array(self::class, 'handleRun'));
        add_action('admin_post_enle_dc_stop_lead', array(self::class, 'handleStopLead'));
    }

    public static function handleSave(): void
    {
        self::guard('enle_dc_save');

        $raw = isset($_POST['enle_dc']) && is_array($_POST['enle_dc']) ? wp_unslash($_POST['enle_dc']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize() whitelists every field.
        $raw['enabled'] = !empty($raw['enabled']);

        Settings::save($raw);
        Plugin::reset();
        Scheduler::schedule();

        self::back('saved');
    }

    public static function handleRun(): void
    {
        self::guard('enle_dc_run');

        $summary = Plugin::runner()->run(true);

        self::back('ran', array(
            'sent'   => count((array) ($summary['sent'] ?? array())),
            'failed' => count((array) ($summary['failed'] ?? array())),
            'status' => (string) ($summary['status'] ?? ''),
        ));
    }

    public static function handleStopLead(): void
    {
        self::guard('enle_dc_stop_lead');

        $id   = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
        $lead = $id > 0 ? Plugin::store()->find($id) : null;

        if ($lead !== null && $lead->isActive()) {
            $lead->status      = Lead::STOPPED;
            $lead->stop_reason = 'manual';
            Plugin::store()->save($lead);
            Plugin::store()->log($lead->id, '', 'stopped', sprintf('Stopped by %s.', wp_get_current_user()->user_login));
        }

        self::back('stopped');
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $s        = Settings::all();
        $last     = get_option(Runner::LAST_RUN_OPTION, array());
        $next     = wp_next_scheduled(Scheduler::DAILY);
        $signals  = Plugin::signals();
        $leads    = Plugin::store()->recent(50);
        $log      = Plugin::store()->recentLog(60);
        $warnings = self::warnings($s, is_array($last) ? $last : array());

        echo '<div class="wrap"><h1>' . esc_html__('Idea-to-Market follow-ups', 'enle-daily-cron') . '</h1>';

        self::notice();

        foreach ($warnings as $warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }

        // Status.
        echo '<h2>' . esc_html__('Status', 'enle-daily-cron') . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
        self::row(__('Sending', 'enle-daily-cron'), $s['enabled'] ? __('On', 'enle-daily-cron') : __('Off', 'enle-daily-cron'));
        self::row(__('Mode', 'enle-daily-cron'), $s['mode'] === Settings::MODE_LIVE
            ? __('Live: emails go to the leads', 'enle-daily-cron')
            : sprintf(__('Dry run: every email goes to %s instead of the lead', 'enle-daily-cron'), $s['preview_email']));
        self::row(__('Next daily run', 'enle-daily-cron'), $next ? gmdate('Y-m-d H:i', (int) $next) . ' UTC' : __('Not scheduled', 'enle-daily-cron'));
        self::row(__('Last run', 'enle-daily-cron'), self::lastRunText(is_array($last) ? $last : array()));
        self::row(__('Claude API key', 'enle-daily-cron'), ClaudeClient::apiKey() !== '' ? __('Found', 'enle-daily-cron') : __('Missing', 'enle-daily-cron'));
        self::row(__('FluentCRM "replied" tag', 'enle-daily-cron'), $signals->repliedTagExists() ? sprintf(__('"%s" exists', 'enle-daily-cron'), $s['replied_tag']) : sprintf(__('"%s" not found', 'enle-daily-cron'), $s['replied_tag']));
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        wp_nonce_field('enle_dc_run');
        echo '<input type="hidden" name="action" value="enle_dc_run">';
        submit_button(
            $s['mode'] === Settings::MODE_LIVE ? __('Run now (sends to leads)', 'enle-daily-cron') : __('Run now (dry run)', 'enle-daily-cron'),
            'secondary',
            'submit',
            false
        );
        echo '</form>';

        self::renderSettings($s);
        self::renderLeads($leads);
        self::renderLog($log);

        echo '</div>';
    }

    /** @param array<string,mixed> $s */
    private static function renderSettings(array $s): void
    {
        echo '<h2>' . esc_html__('Settings', 'enle-daily-cron') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('enle_dc_save');
        echo '<input type="hidden" name="action" value="enle_dc_save"><table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Send emails', 'enle-daily-cron') . '</th><td><label><input type="checkbox" name="enle_dc[enabled]" value="1" ' . checked($s['enabled'], true, false) . '> '
            . esc_html__('Run the sequence every day', 'enle-daily-cron') . '</label></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Mode', 'enle-daily-cron') . '</th><td><select name="enle_dc[mode]">'
            . '<option value="dry_run" ' . selected($s['mode'], Settings::MODE_DRY_RUN, false) . '>' . esc_html__('Dry run (send to the preview inbox)', 'enle-daily-cron') . '</option>'
            . '<option value="live" ' . selected($s['mode'], Settings::MODE_LIVE, false) . '>' . esc_html__('Live (send to the leads)', 'enle-daily-cron') . '</option>'
            . '</select></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Run hour (UTC)', 'enle-daily-cron') . '</th><td><input type="number" min="0" max="23" name="enle_dc[run_hour]" value="' . esc_attr((string) $s['run_hour']) . '" class="small-text"></td></tr>';

        $fields = array(
            'preview_email'   => __('Preview inbox (dry run)', 'enle-daily-cron'),
            'alert_email'     => __('Alerts go to', 'enle-daily-cron'),
            'from_name'       => __('From name', 'enle-daily-cron'),
            'from_email'      => __('From address', 'enle-daily-cron'),
            'reply_to'        => __('Reply-To', 'enle-daily-cron'),
            'programme_url'   => __('Market Validation link', 'enle-daily-cron'),
            'programme_price' => __('Market Validation price', 'enle-daily-cron'),
            'booking_url'     => __('Consultation booking link', 'enle-daily-cron'),
            'product_id'      => __('Market Validation product ID (FluentCart)', 'enle-daily-cron'),
            'replied_tag'     => __('FluentCRM tag that means "replied"', 'enle-daily-cron'),
            'model'           => __('Claude model', 'enle-daily-cron'),
            'retention_days'  => __('Delete finished leads after (days)', 'enle-daily-cron'),
        );

        foreach ($fields as $key => $label) {
            echo '<tr><th scope="row"><label for="enle-dc-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
                . '<input type="text" class="regular-text" id="enle-dc-' . esc_attr($key) . '" name="enle_dc[' . esc_attr($key) . ']" value="' . esc_attr((string) $s[$key]) . '"></td></tr>';
        }

        echo '<tr><th scope="row"><label for="enle-dc-events">' . esc_html__('Consultation event IDs (FluentBooking)', 'enle-daily-cron') . '</label></th><td>'
            . '<input type="text" class="regular-text" id="enle-dc-events" name="enle_dc[booking_event_ids]" value="' . esc_attr(implode(',', (array) $s['booking_event_ids'])) . '">'
            . '<p class="description">' . esc_html__('Comma-separated. A booking for any of these stops the sequence.', 'enle-daily-cron') . '</p></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save settings', 'enle-daily-cron'));
        echo '</form>';
    }

    /** @param array<int,Lead> $leads */
    private static function renderLeads(array $leads): void
    {
        echo '<h2>' . esc_html__('Leads', 'enle-daily-cron') . '</h2>';

        if ($leads === array()) {
            echo '<p>' . esc_html__('No leads yet. A lead is added when someone completes an Idea Validation Report.', 'enle-daily-cron') . '</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach (array(__('Lead', 'enle-daily-cron'), __('Report', 'enle-daily-cron'), __('Report date', 'enle-daily-cron'), __('Status', 'enle-daily-cron'), __('Sent', 'enle-daily-cron'), __('Next', 'enle-daily-cron'), '') as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($leads as $lead) {
            $next_at = $lead->isActive() ? StepPicker::nextDueAt($lead) : 0;
            $status  = $lead->status === Lead::STOPPED ? sprintf('%s (%s)', $lead->status, StopRules::describe($lead->stop_reason)) : $lead->status;

            echo '<tr><td>' . esc_html($lead->name !== '' ? $lead->name : '—') . '<br><small>' . esc_html($lead->email) . '</small></td>';
            echo '<td>' . ($lead->report_url !== '' ? '<a href="' . esc_url($lead->report_url) . '" target="_blank" rel="noopener">' . esc_html($lead->report_title !== '' ? $lead->report_title : __('Report', 'enle-daily-cron')) . '</a>' : esc_html($lead->report_title)) . '</td>';
            echo '<td>' . esc_html(gmdate('Y-m-d', $lead->enrolled_at)) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($lead->sent === array() ? '—' : implode(', ', array_keys($lead->sent))) . ($lead->skipped !== array() ? '<br><small>' . esc_html(sprintf(__('skipped: %s', 'enle-daily-cron'), implode(', ', $lead->skipped))) . '</small>' : '') . '</td>';
            echo '<td>' . esc_html($next_at > 0 ? Steps::label(StepPicker::nextStep($lead)) . ', ' . gmdate('Y-m-d', $next_at) : '—') . '</td><td>';

            if ($lead->isActive()) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('enle_dc_stop_lead');
                echo '<input type="hidden" name="action" value="enle_dc_stop_lead"><input type="hidden" name="lead_id" value="' . esc_attr((string) $lead->id) . '">';
                echo '<button type="submit" class="button button-small">' . esc_html__('Stop', 'enle-daily-cron') . '</button></form>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /** @param array<int,array<string,mixed>> $log */
    private static function renderLog(array $log): void
    {
        echo '<h2>' . esc_html__('Recent activity', 'enle-daily-cron') . '</h2>';

        if ($log === array()) {
            echo '<p>' . esc_html__('Nothing yet.', 'enle-daily-cron') . '</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('When (UTC)', 'enle-daily-cron') . '</th><th>' . esc_html__('Lead', 'enle-daily-cron') . '</th><th>' . esc_html__('Step', 'enle-daily-cron') . '</th><th>' . esc_html__('What', 'enle-daily-cron') . '</th><th>' . esc_html__('Detail', 'enle-daily-cron') . '</th></tr></thead><tbody>';

        foreach ($log as $row) {
            echo '<tr><td>' . esc_html((string) $row['created_at']) . '</td><td>' . esc_html((string) $row['lead_id']) . '</td><td>' . esc_html((string) $row['step']) . '</td><td>' . esc_html((string) $row['action']) . '</td><td>' . esc_html((string) $row['detail']);

            if ((string) $row['body'] !== '') {
                echo '<details><summary>' . esc_html((string) $row['subject']) . '</summary><div style="white-space:pre-wrap;max-width:640px;padding:8px 0">' . esc_html((string) $row['body']) . '</div></details>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $last
     * @return array<int,string>
     */
    private static function warnings(array $s, array $last): array
    {
        $out = array();

        if (ClaudeClient::apiKey() === '') {
            $out[] = __('No Claude API key found: emails can\'t be written. Set it in AI Assistant settings (or the ENLE_DC_CLAUDE_API_KEY constant).', 'enle-daily-cron');
        }

        if (!defined('AI_ASSISTANT_VERSION') || version_compare((string) AI_ASSISTANT_VERSION, '1.10.0', '<')) {
            $out[] = __('AI Assistant 1.10.0 or later is needed: earlier versions don\'t tell this plugin when a report is made, so no leads get added.', 'enle-daily-cron');
        }

        if (!Plugin::signals()->repliedTagExists()) {
            $out[] = sprintf(__('Create the FluentCRM tag "%s". The team adds it to a contact who replies, which stops their emails.', 'enle-daily-cron'), $s['replied_tag']);
        }

        if ($s['enabled'] && !empty($last['started_at']) && time() - (int) $last['started_at'] > Alerter::MISSED_AFTER_HOURS * HOUR_IN_SECONDS) {
            $out[] = __('The last run was more than 36 hours ago. WP-Cron only fires on visits: check that DISABLE_WP_CRON is not set and the site can reach its own wp-cron.php.', 'enle-daily-cron');
        }

        return $out;
    }

    /** @param array<string,mixed> $last */
    private static function lastRunText(array $last): string
    {
        if (empty($last['started_at'])) {
            return __('Never', 'enle-daily-cron');
        }

        return sprintf(
            /* translators: 1: time, 2: mode, 3: sent, 4: stopped, 5: failed */
            __('%1$s UTC (%2$s): %3$d sent, %4$d stopped, %5$d failed', 'enle-daily-cron'),
            gmdate('Y-m-d H:i', (int) $last['started_at']),
            (string) ($last['mode'] ?? ''),
            count((array) ($last['sent'] ?? array())),
            count((array) ($last['stopped'] ?? array())),
            count((array) ($last['failed'] ?? array()))
        );
    }

    private static function notice(): void
    {
        $done = isset($_GET['enle_dc_done']) ? sanitize_key(wp_unslash($_GET['enle_dc_done'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $messages = array(
            'saved'   => __('Settings saved.', 'enle-daily-cron'),
            'stopped' => __('Lead stopped.', 'enle-daily-cron'),
        );

        if ($done === 'ran') {
            $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $text   = $status === 'locked'
                ? __('Another run is in progress; try again in a few minutes.', 'enle-daily-cron')
                : sprintf(__('Run finished: %1$d sent, %2$d failed.', 'enle-daily-cron'), absint($_GET['sent'] ?? 0), absint($_GET['failed'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
        } elseif (isset($messages[$done])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$done]) . '</p></div>';
        }
    }

    private static function row(string $label, string $value): void
    {
        echo '<tr><th style="width:240px">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private static function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'enle-daily-cron'), '', array('response' => 403));
        }

        check_admin_referer($action);
    }

    /** @param array<string,int|string> $args */
    private static function back(string $done, array $args = array()): never
    {
        wp_safe_redirect(add_query_arg(array_merge(array('page' => self::SLUG, 'enle_dc_done' => $done), $args), admin_url('options-general.php')));
        exit;
    }
}
