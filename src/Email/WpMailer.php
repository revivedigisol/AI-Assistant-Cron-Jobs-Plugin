<?php

namespace Enle\DailyCron\Email;

use Enle\DailyCron\Contracts\Mailer;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Sends through wp_mail(), which FluentSMTP routes over SMTP on enle.org.
 * Captures the wp_mail_failed error so the log says why a send failed.
 */
final class WpMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, array $headers = array()): bool|WP_Error
    {
        $error   = null;
        $capture = static function ($wp_error) use (&$error): void {
            $error = $wp_error;
        };

        add_action('wp_mail_failed', $capture);

        $sent = wp_mail(
            $to,
            $subject,
            $html,
            array_merge(array('Content-Type: text/html; charset=UTF-8'), $headers)
        );

        remove_action('wp_mail_failed', $capture);

        if ($sent) {
            return true;
        }

        return new WP_Error(
            'enle_dc_mail_failed',
            'wp_mail() failed' . ($error instanceof WP_Error ? ': ' . $error->get_error_message() : '.')
        );
    }
}
