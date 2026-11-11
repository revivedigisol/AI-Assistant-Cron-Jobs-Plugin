<?php

namespace Enle\DailyCron\Storage;

use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Domain\Lead;

defined('ABSPATH') || exit;

/**
 * LeadStore on the plugin's own tables. Every query with a value goes
 * through $wpdb->prepare(); table names come from $wpdb->prefix only.
 */
final class WpdbLeadStore implements LeadStore
{
    public function save(Lead $lead): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $row = array(
            'email'          => $lead->email,
            'name'           => $lead->name,
            'crm_contact_id' => $lead->crm_contact_id,
            'report_title'   => $lead->report_title,
            'report_url'     => $lead->report_url,
            'report'         => (string) wp_json_encode($lead->report),
            'enrolled_at'    => gmdate('Y-m-d H:i:s', $lead->enrolled_at),
            'status'         => $lead->status,
            'stop_reason'    => $lead->stop_reason,
            'sent'           => (string) wp_json_encode((object) $lead->sent),
            'skipped'        => implode(',', $lead->skipped),
            'updated_at'     => $now,
        );

        if ($lead->id > 0) {
            $wpdb->update(Schema::leads(), $row, array('id' => $lead->id));
        } else {
            $row['created_at'] = $now;
            $wpdb->insert(Schema::leads(), $row);
            $lead->id = (int) $wpdb->insert_id;
        }

        $lead->updated_at = strtotime($now . ' UTC');

        return $lead->id;
    }

    public function find(int $id): ?Lead
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::leads() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findLatestByEmail(string $email): ?Lead
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::leads() . ' WHERE email = %s ORDER BY id DESC LIMIT 1', $email), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function active(int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::leads() . ' WHERE status = %s ORDER BY enrolled_at ASC, id ASC LIMIT %d', Lead::ACTIVE, $limit), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(array(self::class, 'hydrate'), is_array($rows) ? $rows : array());
    }

    public function recent(int $limit, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::leads() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, max(0, $offset)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(array(self::class, 'hydrate'), is_array($rows) ? $rows : array());
    }

    public function countLeads(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::leads()); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
    }

    public function log(int $lead_id, string $step, string $action, string $detail = '', string $subject = '', string $body = ''): void
    {
        global $wpdb;

        $wpdb->insert(
            Schema::log(),
            array(
                'lead_id'    => $lead_id,
                'step'       => substr($step, 0, 20),
                'action'     => substr($action, 0, 20),
                'detail'     => substr(wp_strip_all_tags($detail), 0, 2000),
                'subject'    => substr($subject, 0, 255),
                'body'       => $body,
                'created_at' => gmdate('Y-m-d H:i:s'),
            )
        );
    }

    public function recentLog(int $limit, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::log() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, max(0, $offset)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($rows) ? $rows : array();
    }

    public function countLog(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::log()); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
    }

    public function purgeFinished(int $before): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', $before);

        // Test sends and other rows that belong to no lead.
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::log() . ' WHERE lead_id = 0 AND created_at < %s', $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $ids   = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::leads() . ' WHERE status <> %s AND updated_at < %s', Lead::ACTIVE, $cutoff)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!is_array($ids) || $ids === array()) {
            return 0;
        }

        $ids          = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::log() . " WHERE lead_id IN ({$placeholders})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::leads() . " WHERE id IN ({$placeholders})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): Lead
    {
        $report = json_decode((string) $row['report'], true);
        $sent   = json_decode((string) $row['sent'], true);

        return new Lead(
            id: (int) $row['id'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            crm_contact_id: (int) $row['crm_contact_id'],
            report_title: (string) $row['report_title'],
            report_url: (string) $row['report_url'],
            report: is_array($report) ? array_map('strval', $report) : array(),
            enrolled_at: (int) strtotime($row['enrolled_at'] . ' UTC'),
            status: (string) $row['status'],
            stop_reason: (string) $row['stop_reason'],
            sent: is_array($sent) ? array_map('intval', $sent) : array(),
            skipped: array_values(array_filter(explode(',', (string) $row['skipped']))),
            updated_at: (int) strtotime($row['updated_at'] . ' UTC'),
        );
    }
}
