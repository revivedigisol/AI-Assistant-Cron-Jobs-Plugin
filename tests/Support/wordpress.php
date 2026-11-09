<?php
/**
 * Minimal WordPress stand-ins: only what the tested code calls, behaving
 * like core for the inputs the tests use. State lives in WpStub so each
 * test can inspect and reset it.
 */

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS   = 3600;
const DAY_IN_SECONDS    = 86400;

final class WpStub
{
    /** @var array<string,mixed> */
    public static array $options = array();

    /** @var array<string,array<int,callable>> */
    public static array $filters = array();

    /** @var array<int,array{url:string,args:array<string,mixed>}> */
    public static array $http = array();

    /** @var array<int,mixed> Responses returned by wp_remote_post(), in order; the last one repeats. */
    public static array $http_responses = array();

    /** @var array<string,int> hook => timestamp */
    public static array $cron = array();

    /** @var array<int,array{to:string,subject:string,message:string,headers:mixed}> */
    public static array $mails = array();

    public static bool $mail_result = true;

    public static function reset(): void
    {
        self::$options        = array();
        self::$filters        = array();
        self::$http           = array();
        self::$http_responses = array();
        self::$cron           = array();
        self::$mails          = array();
        self::$mail_result    = true;
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '', private mixed $data = '')
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return esc_html($text);
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return esc_html($text);
}

function esc_url($url)
{
    return esc_html((string) $url);
}

function esc_url_raw($url, $protocols = null)
{
    $url    = trim((string) $url);
    $scheme = parse_url($url, PHP_URL_SCHEME);

    if ($protocols !== null && !in_array($scheme, (array) $protocols, true)) {
        return '';
    }

    return $url;
}

function sanitize_text_field($str)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags((string) $str)));
}

function sanitize_textarea_field($str)
{
    return trim(strip_tags((string) $str));
}

function sanitize_email($email)
{
    $email = trim((string) $email);

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function is_email($email)
{
    return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function absint($value)
{
    return abs((int) $value);
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options | JSON_UNESCAPED_UNICODE, $depth);
}

function wp_strip_all_tags($text, $remove_breaks = false)
{
    return trim(strip_tags((string) $text));
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/\\');
}

function trailingslashit($value)
{
    return untrailingslashit($value) . '/';
}

function wp_parse_url($url, $component = -1)
{
    return parse_url((string) $url, $component);
}

function home_url($path = '')
{
    return 'https://enle.org' . $path;
}

function admin_url($path = '')
{
    return 'https://enle.org/wp-admin/' . $path;
}

function add_query_arg($key, $value, $url)
{
    return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode((string) $value);
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt-' . $scheme;
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

function current_time($type)
{
    return gmdate('Y-m-d H:i:s');
}

function get_option($name, $default = false)
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    WpStub::$options[$name] = $value;

    return true;
}

function delete_option($name)
{
    unset(WpStub::$options[$name]);

    return true;
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    WpStub::$filters[$hook][] = $callback;

    return true;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_action($hook, $callback, $priority = 10)
{
    foreach (WpStub::$filters[$hook] ?? array() as $i => $registered) {
        if ($registered === $callback) {
            unset(WpStub::$filters[$hook][$i]);
        }
    }

    return true;
}

function apply_filters($hook, $value, ...$args)
{
    foreach (WpStub::$filters[$hook] ?? array() as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function do_action($hook, ...$args)
{
    foreach (WpStub::$filters[$hook] ?? array() as $callback) {
        $callback(...$args);
    }
}

function wp_remote_post($url, $args = array())
{
    WpStub::$http[] = array('url' => $url, 'args' => $args);

    $index = min(count(WpStub::$http) - 1, count(WpStub::$http_responses) - 1);

    return $index >= 0 ? WpStub::$http_responses[$index] : new WP_Error('no_response', 'No scripted response.');
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function wp_next_scheduled($hook, $args = array())
{
    return WpStub::$cron[$hook] ?? false;
}

function wp_schedule_single_event($timestamp, $hook, $args = array())
{
    WpStub::$cron[$hook] = (int) $timestamp;

    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array())
{
    WpStub::$cron[$hook] = (int) $timestamp;

    return true;
}

function wp_clear_scheduled_hook($hook, $args = array())
{
    unset(WpStub::$cron[$hook]);

    return 1;
}

function wp_mail($to, $subject, $message, $headers = '', $attachments = array())
{
    WpStub::$mails[] = array('to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers);

    if (!WpStub::$mail_result) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP connect() failed.'));
    }

    return WpStub::$mail_result;
}
