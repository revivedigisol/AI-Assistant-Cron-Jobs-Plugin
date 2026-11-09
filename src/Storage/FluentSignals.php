<?php

namespace Enle\DailyCron\Storage;

use Enle\DailyCron\Contracts\Signals;
use Enle\DailyCron\Settings;

defined('ABSPATH') || exit;

/**
 * Signals read straight from the FluentCart, FluentBooking and FluentCRM
 * tables on this site (checked on enle.org, 2026-11-09). Each check returns
 * "nothing found" when its plugin's tables are missing, so a deactivated
 * plugin never blocks the sequence or causes a fatal error.
 */
final class FluentSignals implements Signals
{
    /** @var array<string,bool> */
    private array $tables = array();

    public function hasPurchased(string $email): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        if (!$this->tablesExist(array('fct_orders', 'fct_order_items', 'fct_customers'))) {
            return false;
        }

        $sql = "SELECT 1 FROM {$p}fct_orders o
            INNER JOIN {$p}fct_customers c ON c.id = o.customer_id
            INNER JOIN {$p}fct_order_items i ON i.order_id = o.id
            WHERE c.email = %s AND i.post_id = %d AND o.payment_status IN ('paid', 'partially_paid')
            LIMIT 1";

        return (bool) $wpdb->get_var($wpdb->prepare($sql, $email, (int) Settings::get('product_id'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function hasBookedSince(string $email, int $since): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $events = (array) Settings::get('booking_event_ids');

        if ($events === array() || !$this->tablesExist(array('fcal_bookings'))) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($events), '%d'));
        $sql          = "SELECT 1 FROM {$p}fcal_bookings
            WHERE email = %s AND event_id IN ({$placeholders}) AND created_at >= %s
            AND status NOT IN ('cancelled', 'rejected')
            LIMIT 1";

        return (bool) $wpdb->get_var($wpdb->prepare($sql, array_merge(array($email), $events, array(gmdate('Y-m-d H:i:s', $since))))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function crmStatus(string $email): string
    {
        global $wpdb;
        $p = $wpdb->prefix;

        if (!$this->tablesExist(array('fc_subscribers'))) {
            return '';
        }

        return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}fc_subscribers WHERE email = %s LIMIT 1", $email)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function hasRepliedTag(string $email): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        if (!$this->tablesExist(array('fc_subscribers', 'fc_subscriber_pivot', 'fc_tags'))) {
            return false;
        }

        $sql = "SELECT 1 FROM {$p}fc_subscribers s
            INNER JOIN {$p}fc_subscriber_pivot pv ON pv.subscriber_id = s.id AND pv.object_type LIKE %s
            INNER JOIN {$p}fc_tags t ON t.id = pv.object_id
            WHERE s.email = %s AND t.title = %s
            LIMIT 1";

        return (bool) $wpdb->get_var($wpdb->prepare($sql, '%Tag', $email, (string) Settings::get('replied_tag'))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function unsubscribeInCrm(string $email): void
    {
        global $wpdb;

        if (!$this->tablesExist(array('fc_subscribers'))) {
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'fc_subscribers',
            array('status' => 'unsubscribed', 'updated_at' => current_time('mysql')),
            array('email' => $email)
        );
    }

    /** Whether the team's "replied" tag exists in FluentCRM (shown on the admin page). */
    public function repliedTagExists(): bool
    {
        global $wpdb;

        if (!$this->tablesExist(array('fc_tags'))) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}fc_tags WHERE title = %s LIMIT 1", (string) Settings::get('replied_tag'))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @param array<int,string> $names Table names without the prefix. */
    private function tablesExist(array $names): bool
    {
        global $wpdb;

        foreach ($names as $name) {
            $table = $wpdb->prefix . $name;

            if (!isset($this->tables[$table])) {
                $this->tables[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
            }

            if (!$this->tables[$table]) {
                return false;
            }
        }

        return true;
    }
}
