<?php

namespace Enle\DailyCron\Email;

use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Contracts\Signals;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Sequence\StopRules;

defined('ABSPATH') || exit;

/**
 * Unsubscribe links: https://enle.org/?enle_dc_unsub=<lead id>.<signature>
 *
 * - The signature is an HMAC of the lead id and email with a site secret, so
 *   a link only works for its own lead and can't be guessed or edited.
 * - GET shows a confirmation button (link scanners in mail clients open GET
 *   links, and must not unsubscribe people). POST unsubscribes, including
 *   RFC 8058 one-click (List-Unsubscribe-Post) from the mail client.
 * - Unsubscribing stops the sequence and sets the FluentCRM contact to
 *   unsubscribed.
 */
final class Unsubscribe
{
    public const QUERY_ARG = 'enle_dc_unsub';

    public function __construct(private LeadStore $store, private Signals $signals)
    {
    }

    public static function url(Lead $lead): string
    {
        return add_query_arg(self::QUERY_ARG, $lead->id . '.' . self::signature($lead), home_url('/'));
    }

    public static function signature(Lead $lead): string
    {
        return substr(hash_hmac('sha256', $lead->id . '|' . strtolower($lead->email), self::secret()), 0, 32);
    }

    /**
     * Finds the lead a token belongs to; null if the token is malformed or forged.
     */
    public function leadForToken(string $token): ?Lead
    {
        if (!preg_match('/^(\d{1,18})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }

        $lead = $this->store->find((int) $m[1]);

        return $lead !== null && hash_equals(self::signature($lead), $m[2]) ? $lead : null;
    }

    /** Stops the lead and unsubscribes them in FluentCRM. Idempotent. */
    public function unsubscribe(Lead $lead): void
    {
        if ($lead->status === Lead::STOPPED && $lead->stop_reason === StopRules::UNSUBSCRIBED) {
            return;
        }

        $lead->status      = Lead::STOPPED;
        $lead->stop_reason = StopRules::UNSUBSCRIBED;
        $this->store->save($lead);
        $this->store->log($lead->id, '', 'stopped', 'Unsubscribed through the email link.');
        $this->signals->unsubscribeInCrm($lead->email);
    }

    /** Hooked on 'init'; only acts when the query arg is present. */
    public function maybeHandle(): void
    {
        if (!isset($_GET[self::QUERY_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $token  = sanitize_text_field(wp_unslash((string) $_GET[self::QUERY_ARG])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $lead   = $this->leadForToken($token);
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        if ($lead === null) {
            self::page(__('This unsubscribe link is not valid. If you keep getting emails you don\'t want, reply to one and we\'ll remove you.', 'enle-daily-cron'), 400);
        }

        // The signed token is the authorisation here: the person is logged
        // out, so a WordPress nonce can't apply.
        if ($method === 'POST') {
            $this->unsubscribe($lead);
            self::page(__('You\'re unsubscribed. We won\'t send you any more of these emails.', 'enle-daily-cron'));
        }

        $action = esc_url(self::url($lead));
        self::page(
            __('Stop receiving follow-up emails about your Idea Validation Report?', 'enle-daily-cron'),
            200,
            '<form method="post" action="' . $action . '"><button type="submit" style="font-size:16px;padding:10px 18px;cursor:pointer;">'
                . esc_html__('Unsubscribe', 'enle-daily-cron') . '</button></form>'
        );
    }

    private static function page(string $message, int $status = 200, string $form_html = ''): never
    {
        status_header($status);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . esc_html__('Enle Business Hub', 'enle-daily-cron') . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;color:#1f2937;line-height:1.6;">'
            . '<h1 style="font-size:20px;">' . esc_html__('Enle Business Hub', 'enle-daily-cron') . '</h1>'
            . '<p>' . esc_html($message) . '</p>'
            . $form_html // Built above from escaped parts only.
            . '</body></html>';

        exit;
    }

    private static function secret(): string
    {
        return wp_salt('auth') . '|enle-daily-cron-unsubscribe';
    }
}
