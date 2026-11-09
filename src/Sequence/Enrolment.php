<?php

namespace Enle\DailyCron\Sequence;

use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Domain\Lead;

defined('ABSPATH') || exit;

/**
 * Puts a founder into the sequence when ai-assistant finishes their Idea
 * Validation Report (action 'enle_idea_validation_report_ready').
 *
 * Only new reports are enrolled (agreed 2026-11-09): there is no backfill.
 */
final class Enrolment
{
    public const ACTION = 'enle_idea_validation_report_ready';

    /** Report sections kept for the prompt, with a size cap each. */
    private const SECTIONS = array('summary', 'offer', 'icp', 'positioning', 'messaging', 'roadmap', 'verdict');

    private const SECTION_MAX = 6000;

    public function __construct(private LeadStore $store)
    {
    }

    /**
     * @param mixed $payload From ai-assistant: email, name, crm_contact_id, crm_url, title, file_url, sections.
     * @return array{result:string,lead_id:int}
     *         result: enrolled | updated | ignored_invalid | ignored_other_business | ignored_unsubscribed
     */
    public function handle(mixed $payload, int $now): array
    {
        if (!is_array($payload)) {
            return array('result' => 'ignored_invalid', 'lead_id' => 0);
        }

        $email = sanitize_email((string) ($payload['email'] ?? ''));

        if (!is_email($email)) {
            return array('result' => 'ignored_invalid', 'lead_id' => 0);
        }

        // ai-assistant also validates ideas for client businesses (e.g. the
        // redgoldtraders context), saved to their own CRM. Those founders
        // aren't Enle's leads, so only enle.org's CRM counts.
        $crm_host = wp_parse_url((string) ($payload['crm_url'] ?? ''), PHP_URL_HOST);

        if (!apply_filters('enle_dc_enrol_crm_host', $crm_host === 'enle.org', $crm_host, $payload)) {
            return array('result' => 'ignored_other_business', 'lead_id' => 0);
        }

        $report = $this->sections($payload['sections'] ?? array());

        if ($report === array()) {
            return array('result' => 'ignored_invalid', 'lead_id' => 0);
        }

        $existing = $this->store->findLatestByEmail($email);

        if ($existing !== null && $existing->status === Lead::STOPPED && $existing->stop_reason === StopRules::UNSUBSCRIBED) {
            return array('result' => 'ignored_unsubscribed', 'lead_id' => $existing->id);
        }

        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $url   = esc_url_raw((string) ($payload['file_url'] ?? ''), array('https', 'http'));
        $name  = sanitize_text_field((string) ($payload['name'] ?? ''));
        $crm   = absint($payload['crm_contact_id'] ?? 0);

        // Already in the sequence: keep their schedule, use the newer report.
        if ($existing !== null && $existing->isActive()) {
            $existing->report       = $report;
            $existing->report_title = $title;
            $existing->report_url   = $url;
            $existing->name         = $name !== '' ? $name : $existing->name;
            $existing->crm_contact_id = $crm > 0 ? $crm : $existing->crm_contact_id;
            $this->store->save($existing);
            $this->store->log($existing->id, '', 'updated', 'A newer report replaced the stored one: ' . $title);

            return array('result' => 'updated', 'lead_id' => $existing->id);
        }

        $lead = new Lead(
            id: 0,
            email: $email,
            name: $name,
            crm_contact_id: $crm,
            report_title: $title,
            report_url: $url,
            report: $report,
            enrolled_at: $now,
        );

        $id = $this->store->save($lead);
        $this->store->log($id, '', 'enrolled', $title);

        return array('result' => 'enrolled', 'lead_id' => $id);
    }

    /**
     * @param mixed $sections
     * @return array<string,string>
     */
    private function sections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return array();
        }

        // ai-assistant names the summary 'executive_summary' in its sections.
        if (!isset($sections['summary']) && isset($sections['executive_summary'])) {
            $sections['summary'] = $sections['executive_summary'];
        }

        $out = array();

        foreach (self::SECTIONS as $key) {
            $text = isset($sections[$key]) ? trim(sanitize_textarea_field((string) $sections[$key])) : '';

            if ($text !== '') {
                $out[$key] = mb_substr($text, 0, self::SECTION_MAX);
            }
        }

        // Without the core of the report there's nothing to personalise from.
        return isset($out['summary']) || isset($out['offer']) ? $out : array();
    }
}
