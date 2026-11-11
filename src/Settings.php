<?php

namespace Enle\DailyCron;

defined('ABSPATH') || exit;

/**
 * Plugin settings: one option, whitelisted and clamped on every read and
 * save, so a hand-edited or corrupt option can't feed bad values in.
 */
final class Settings
{
    public const OPTION = 'enle_dc_settings';

    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE    = 'live';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array(
            // Off until an admin switches it on; then dry-run first.
            'enabled'          => false,
            'mode'             => self::MODE_DRY_RUN,
            'run_hour'         => 9,
            'alert_email'      => 'contact@enle.org',
            'preview_email'    => 'contact@enle.org',
            'from_email'       => 'contact@enle.org',
            'from_name'        => 'Monsur at Enle',
            // Empty: the From name up to " at " ("Monsur at Enle" signs as "Monsur").
            'signoff_name'     => '',
            'reply_to'         => 'contact@enle.org',
            'programme_url'    => 'https://enle.org/market-validation/',
            'booking_url'      => 'https://enle.org/book-an-appointment-now/',
            'programme_price'  => '£1,000',
            'model'            => 'claude-opus-5-5',
            'product_id'       => 2688,
            'booking_event_ids' => array(27, 28),
            'replied_tag'      => 'Replied',
            'retention_days'   => 180,
        );
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, array());

        return self::sanitize(is_array($stored) ? $stored : array());
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The name the emails are signed with: the "Sign-off name" setting, or
     * else the From name up to " at " ("Mariam at Enle" gives "Mariam").
     */
    public static function signoffName(): string
    {
        $name = trim((string) self::get('signoff_name'));

        if ($name === '') {
            $name = trim((string) preg_split('/\s+at\s+/i', (string) self::get('from_name'))[0]);
        }

        return $name !== '' ? $name : 'Monsur';
    }

    /**
     * @param array<string,mixed> $input Raw input (admin form or stored option).
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $d   = self::defaults();
        $out = array();

        $out['enabled']  = isset($input['enabled']) ? (bool) $input['enabled'] : $d['enabled'];
        $out['mode']     = isset($input['mode']) && $input['mode'] === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_DRY_RUN;
        $out['run_hour'] = isset($input['run_hour']) ? max(0, min(23, (int) $input['run_hour'])) : $d['run_hour'];

        foreach (array('alert_email', 'preview_email', 'from_email', 'reply_to') as $key) {
            $email     = isset($input[$key]) ? sanitize_email((string) $input[$key]) : '';
            $out[$key] = is_email($email) ? $email : $d[$key];
        }

        $out['from_name'] = isset($input['from_name']) && trim((string) $input['from_name']) !== ''
            ? substr(sanitize_text_field((string) $input['from_name']), 0, 80)
            : $d['from_name'];

        $out['signoff_name'] = isset($input['signoff_name']) ? substr(trim(sanitize_text_field((string) $input['signoff_name'])), 0, 40) : $d['signoff_name'];

        foreach (array('programme_url', 'booking_url') as $key) {
            $url       = isset($input[$key]) ? esc_url_raw((string) $input[$key], array('https')) : '';
            $out[$key] = $url !== '' && self::isEnleUrl($url) ? $url : $d[$key];
        }

        $out['programme_price'] = isset($input['programme_price']) && trim((string) $input['programme_price']) !== ''
            ? substr(sanitize_text_field((string) $input['programme_price']), 0, 20)
            : $d['programme_price'];

        $model        = isset($input['model']) ? (string) $input['model'] : '';
        $out['model'] = preg_match('/^claude-[a-z0-9.-]{1,60}$/', $model) ? $model : $d['model'];

        $out['product_id'] = isset($input['product_id']) && (int) $input['product_id'] > 0 ? (int) $input['product_id'] : $d['product_id'];

        $events = isset($input['booking_event_ids']) ? $input['booking_event_ids'] : $d['booking_event_ids'];
        if (is_string($events)) {
            $events = explode(',', $events);
        }
        $events                   = array_values(array_unique(array_filter(array_map('intval', (array) $events), static fn ($id) => $id > 0)));
        $out['booking_event_ids'] = $events;

        $out['replied_tag'] = isset($input['replied_tag']) && trim((string) $input['replied_tag']) !== ''
            ? substr(sanitize_text_field((string) $input['replied_tag']), 0, 60)
            : $d['replied_tag'];

        $out['retention_days'] = isset($input['retention_days']) ? max(30, min(730, (int) $input['retention_days'])) : $d['retention_days'];

        return $out;
    }

    /** @param array<string,mixed> $input */
    public static function save(array $input): array
    {
        $clean = self::sanitize($input);
        update_option(self::OPTION, $clean, false);

        return $clean;
    }

    public static function isLive(): bool
    {
        return self::get('mode') === self::MODE_LIVE;
    }

    /**
     * Links in the emails may only point at enle.org: the programme and
     * booking URLs are the only links a lead ever receives from this plugin.
     */
    public static function isEnleUrl(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        return wp_parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($host)
            && ($host === 'enle.org' || str_ends_with($host, '.enle.org'));
    }
}
