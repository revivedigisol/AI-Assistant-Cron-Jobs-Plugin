<?php

namespace Enle\DailyCron\Email;

use Enle\DailyCron\Settings;

defined('ABSPATH') || exit;

/**
 * Turns the plain-text email into simple HTML. Everything is escaped; the
 * only markup added is paragraphs, line breaks, links to enle.org (already
 * checked by Validator) and the unsubscribe footer.
 */
final class Renderer
{
    public static function html(string $body, string $unsubscribe_url): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($body)) ?: array();
        $html       = '';

        foreach ($paragraphs as $paragraph) {
            $lines = array_map(array(self::class, 'line'), explode("\n", $paragraph));
            $html .= '<p style="margin:0 0 16px 0;">' . implode('<br>', $lines) . "</p>\n";
        }

        $footer = sprintf(
            /* translators: %s: unsubscribe link */
            esc_html__('You are receiving this because you used Enle\'s Idea Validation Assistant. %s', 'enle-daily-cron'),
            '<a href="' . esc_url($unsubscribe_url) . '" style="color:#666666;">' . esc_html__('Unsubscribe from these emails', 'enle-daily-cron') . '</a>'
        );

        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#ffffff;color:#1f2937;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;">'
            . '<div style="max-width:600px;">' . $html
            . '<p style="margin:32px 0 0 0;font-size:12px;color:#666666;border-top:1px solid #e5e7eb;padding-top:12px;">' . $footer . '</p>'
            . '</div></body></html>';
    }

    private static function line(string $line): string
    {
        $parts = preg_split('#(https://[^\s<>()"\']+)#', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: array($line);
        $out   = '';

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $url   = rtrim($part, '.,;:!?');
                $tail  = substr($part, strlen($url));
                $out  .= Settings::isEnleUrl($url)
                    ? '<a href="' . esc_url($url) . '" style="color:#0b5cad;">' . esc_html($url) . '</a>' . esc_html($tail)
                    : esc_html($part);
            } else {
                $out .= esc_html($part);
            }
        }

        return $out;
    }
}
