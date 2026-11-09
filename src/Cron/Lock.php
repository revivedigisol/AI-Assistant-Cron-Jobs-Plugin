<?php

namespace Enle\DailyCron\Cron;

use Enle\DailyCron\Contracts\RunLock;

defined('ABSPATH') || exit;

/**
 * "Only one run at a time" lock. enle.org has no persistent object cache,
 * so transients aren't atomic; instead this inserts a row into wp_options,
 * whose option_name is unique, and takes over a stale lock with a
 * compare-and-swap UPDATE. Queries bypass the options cache on purpose.
 */
final class Lock implements RunLock
{
    private const NAME = 'enle_dc_lock';

    private string $token = '';

    public function __construct(private int $ttl = 900)
    {
    }

    public function acquire(): bool
    {
        global $wpdb;

        $token = wp_generate_password(20, false, false);
        $value = $token . '|' . (time() + $this->ttl);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::NAME,
            $value
        ));

        if ($inserted === 1) {
            $this->token = $value;

            return true;
        }

        $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::NAME));
        $expires = (int) (explode('|', $current)[1] ?? 0);

        if ($expires > time()) {
            return false;
        }

        // Stale (a run died): take it over only if nobody else just did.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            self::NAME,
            $current
        ));

        if ($updated === 1) {
            $this->token = $value;

            return true;
        }

        return false;
    }

    public function release(): void
    {
        global $wpdb;

        if ($this->token === '') {
            return;
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::NAME, $this->token));
        $this->token = '';
    }

    public static function clear(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::NAME));
    }
}
