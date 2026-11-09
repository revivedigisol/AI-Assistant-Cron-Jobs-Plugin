<?php

namespace Enle\DailyCron\Cron;

use Enle\DailyCron\Alerts\Alerter;
use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Contracts\Mailer;
use Enle\DailyCron\Contracts\RunLock;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Email\Renderer;
use Enle\DailyCron\Email\Unsubscribe;
use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\StepPicker;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Settings;
use Throwable;

defined('ABSPATH') || exit;

/**
 * The daily run: for each active lead, apply the stop rules, then send the
 * step that is due (at most one per lead per run).
 *
 * In dry-run mode every email is written and checked exactly as in live
 * mode, but goes to the preview inbox instead of the lead, and the lead's
 * schedule still moves on, so the team sees the real sequence.
 */
final class Runner
{
    public const LAST_RUN_OPTION = 'enle_dc_last_run';

    private const BATCH = 100;

    /** Leave headroom under PHP and proxy time limits. */
    private const TIME_BUDGET = 240;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private LeadStore $store,
        private StopRules $stop_rules,
        private Writer $writer,
        private Mailer $mailer,
        private Alerter $alerter,
        private RunLock $lock,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param bool $force Run even when the plugin is switched off (admin "Run now", WP-CLI).
     * @return array<string,mixed> Summary.
     */
    public function run(bool $force = false, ?int $now = null): array
    {
        $now = $now ?? time();

        if (!$force && !Settings::get('enabled')) {
            return array('status' => 'disabled');
        }

        if (!$this->lock->acquire()) {
            return array('status' => 'locked');
        }

        $previous = get_option(self::LAST_RUN_OPTION, array());
        $summary  = array(
            'status'                   => 'ok',
            'mode'                     => Settings::isLive() ? Settings::MODE_LIVE : Settings::MODE_DRY_RUN,
            'started_at'               => $now,
            'hours_since_previous_run' => is_array($previous) && !empty($previous['started_at']) ? round(($now - (int) $previous['started_at']) / HOUR_IN_SECONDS, 1) : 0,
            'sent'                     => array(),
            'stopped'                  => array(),
            'skipped'                  => 0,
            'completed'                => 0,
            'failed'                   => array(),
            'more_to_do'               => false,
        );

        $started = ($this->clock)();

        try {
            foreach ($this->store->active(self::BATCH) as $i => $lead) {
                if (($this->clock)() - $started > self::TIME_BUDGET) {
                    $summary['more_to_do'] = true;
                    break;
                }

                $this->processLead($lead, $now, $summary);
            }

            $this->store->purgeFinished($now - (int) Settings::get('retention_days') * DAY_IN_SECONDS);
        } catch (Throwable $e) {
            $summary['status']   = 'error';
            $summary['failed'][] = array('name' => '', 'email' => '', 'step' => '-', 'error' => 'Run stopped: ' . $e->getMessage());
        } finally {
            $this->lock->release();
        }

        $summary['finished_at'] = time();
        update_option(self::LAST_RUN_OPTION, $summary, false);

        if ($summary['more_to_do']) {
            Scheduler::continueSoon();
        }

        $this->alerter->runSummary($summary);

        return $summary;
    }

    /**
     * Stop-rule check only, no emails: runs soon after a purchase or booking.
     *
     * @return int Leads stopped.
     */
    public function sweepStops(): int
    {
        if (!$this->lock->acquire()) {
            Scheduler::sweepSoon();

            return 0;
        }

        $stopped = 0;

        try {
            foreach ($this->store->active(self::BATCH * 5) as $lead) {
                $reason = $this->stop_rules->reasonToStop($lead);

                if ($reason !== '') {
                    $this->stop($lead, $reason);
                    $stopped++;
                }
            }
        } finally {
            $this->lock->release();
        }

        return $stopped;
    }

    /** @param array<string,mixed> $summary */
    private function processLead(Lead $lead, int $now, array &$summary): void
    {
        try {
            $reason = $this->stop_rules->reasonToStop($lead);

            if ($reason !== '') {
                $this->stop($lead, $reason);
                $summary['stopped'][] = array('email' => $lead->email, 'reason' => $reason);

                return;
            }

            $decision = StepPicker::decide($lead, $now);

            foreach ($decision['skip'] as $step) {
                $lead->skipped[] = $step;
                $this->store->log($lead->id, $step, 'skipped', 'Its sending window passed before it could go out.');
                $summary['skipped']++;
            }

            if ($decision['send'] !== null) {
                $this->sendStep($lead, $decision['send'], $now, $summary);
            }

            if (StepPicker::nextStep($lead) === '') {
                $lead->status = Lead::COMPLETED;
                $this->store->log($lead->id, '', 'completed', 'All steps sent or skipped.');
                $summary['completed']++;
            }

            $this->store->save($lead);
        } catch (Throwable $e) {
            $this->store->log($lead->id, '', 'failed', 'Unexpected error: ' . $e->getMessage());
            $summary['failed'][] = array('name' => $lead->name, 'email' => $lead->email, 'step' => '-', 'error' => 'Unexpected error: ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $summary */
    private function sendStep(Lead $lead, string $step, int $now, array &$summary): void
    {
        $draft = $this->writer->write($lead, $step);

        if (is_wp_error($draft)) {
            $this->fail($lead, $step, $draft->get_error_message(), $summary);

            return;
        }

        $live            = Settings::isLive();
        $unsubscribe_url = Unsubscribe::url($lead);
        $html            = Renderer::html($draft['body'], $unsubscribe_url);
        $to              = $live ? $lead->email : (string) Settings::get('preview_email');
        $subject         = $live
            ? $draft['subject']
            : sprintf('[Preview: %s to %s <%s>] %s', Steps::label($step), $lead->name !== '' ? $lead->name : 'lead', $lead->email, $draft['subject']);

        $headers = array(
            sprintf('From: %s <%s>', self::headerText((string) Settings::get('from_name')), Settings::get('from_email')),
            'Reply-To: ' . Settings::get('reply_to'),
        );

        if ($live) {
            $headers[] = 'List-Unsubscribe: <' . esc_url_raw($unsubscribe_url) . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        $result = $this->mailer->send($to, $subject, $html, $headers);

        if ($result !== true) {
            $this->fail($lead, $step, is_wp_error($result) ? $result->get_error_message() : 'Sending failed.', $summary);

            return;
        }

        $lead->sent[$step] = $now;
        $this->store->log($lead->id, $step, $live ? 'sent' : 'previewed', 'To ' . $to, $draft['subject'], $draft['body']);
        $summary['sent'][] = array('email' => $lead->email, 'step' => $step);
    }

    /** @param array<string,mixed> $summary */
    private function fail(Lead $lead, string $step, string $error, array &$summary): void
    {
        $this->store->log($lead->id, $step, 'failed', $error);
        $summary['failed'][] = array('name' => $lead->name, 'email' => $lead->email, 'step' => $step, 'error' => $error);
    }

    private function stop(Lead $lead, string $reason): void
    {
        $lead->status      = Lead::STOPPED;
        $lead->stop_reason = $reason;
        $this->store->save($lead);
        $this->store->log($lead->id, '', 'stopped', 'Lead ' . StopRules::describe($reason) . '.');

        if (StopRules::needsTeam($reason)) {
            $this->alerter->teamAction($lead, $reason);
        }
    }

    /** Strips characters that could break or inject a mail header. */
    private static function headerText(string $text): string
    {
        return trim(str_replace(array("\r", "\n", '<', '>', '"'), '', $text));
    }
}
