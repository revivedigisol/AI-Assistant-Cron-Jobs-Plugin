<?php

namespace Enle\DailyCron\Email;

use Enle\DailyCron\Sequence\Steps;

defined('ABSPATH') || exit;

/**
 * Checks a drafted email against the sequence rules before anything is sent.
 * The prompt asks for all of this; this is the backstop, because the report
 * text in the prompt comes from a public chat and the model can still slip.
 */
final class Validator
{
    private const MIN_WORDS = 80;
    private const MAX_WORDS = 450;

    /**
     * @param array{programme_url:string,booking_url:string,programme_price:string} $links
     * @return array<int,string> Problems found; empty means OK to send.
     */
    public static function check(string $step, string $subject, string $body, string $first_name, array $links, string $signer = 'Monsur'): array
    {
        $errors = array();

        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > 120 || preg_match('/[\r\n]/', $subject)) {
            $errors[] = 'The subject must be one line of at most 120 characters.';
        }

        $words = str_word_count(wp_strip_all_tags($body));
        if ($words < self::MIN_WORDS || $words > self::MAX_WORDS) {
            $errors[] = sprintf('The body must be %d–%d words (it was %d).', self::MIN_WORDS, self::MAX_WORDS, $words);
        }

        if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $body . $subject)) {
            $errors[] = 'No HTML tags: write plain text.';
        }

        if (preg_match('/\[[^\]]{1,40}\]|\{\{|\}\}|##/', $body . $subject)) {
            $errors[] = 'No placeholders, brackets or template codes such as [Name] or {{…}}.';
        }

        if (preg_match('/^\s*(#|\*\*|- )/m', $body)) {
            $errors[] = 'No Markdown (headings, bold or "-" bullets); plain sentences only.';
        }

        $greeting = $first_name !== '' ? 'Hi ' . $first_name : 'Hi';
        if (stripos(ltrim($body), $greeting) !== 0) {
            $errors[] = sprintf('The email must open with "%s".', $first_name !== '' ? 'Hi ' . $first_name . ',' : 'Hi there,');
        }

        if (!preg_match('/' . preg_quote($signer, '/') . '\s*\R\s*Enle Business Hub\s*$/u', rtrim($body))) {
            $errors[] = sprintf('The email must end with the sign-off "Warm regards," / "%s" / "Enle Business Hub".', $signer);
        }

        // Links: only the ones the brief allows, exactly as given.
        $urls    = self::urls($body . ' ' . $subject);
        $allowed = match ($step) {
            Steps::DAY3 => array(),
            default     => array(untrailingslashit($links['programme_url']), untrailingslashit($links['booking_url'])),
        };

        foreach ($urls as $url) {
            if (!in_array(untrailingslashit($url), $allowed, true)) {
                $errors[] = 'Link not allowed in this email: ' . $url;
            }
        }

        if (preg_match('/@[a-z0-9-]+\.[a-z]/i', $body)) {
            $errors[] = 'No email addresses in the body (replies go to the Reply-To address).';
        }

        $mentions_price = str_contains($body . $subject, '£') || stripos($body . $subject, $links['programme_price']) !== false;

        switch ($step) {
            case Steps::DAY3:
                if ($mentions_price || stripos($body . $subject, 'Market Validation') !== false || stripos($body, 'consultation') !== false) {
                    $errors[] = 'Day 3 must not mention a price, Market Validation or a consultation.';
                }
                break;

            case Steps::DAY7:
                if (stripos($body, $links['programme_price']) === false) {
                    $errors[] = 'Day 7 must state the programme price exactly as ' . $links['programme_price'] . '.';
                }
                if (!in_array(untrailingslashit($links['programme_url']), array_map('untrailingslashit', $urls), true)) {
                    $errors[] = 'Day 7 must include the Market Validation link ' . $links['programme_url'] . '.';
                }
                break;

            case Steps::DAY14:
                foreach (array(1, 2, 3) as $n) {
                    if (!preg_match('/^\s*' . $n . '[.)]\s+\S/m', $body)) {
                        $errors[] = 'Day 14 must list the three numbered options (1., 2., 3.) on their own lines.';
                        break;
                    }
                }
                if ($mentions_price) {
                    $errors[] = 'Day 14 must not state a price.';
                }
                break;
        }

        return array_values(array_unique($errors));
    }

    /** @return array<int,string> */
    public static function urls(string $text): array
    {
        preg_match_all('#\b(?:https?://|www\.)[^\s<>()"\']+#i', $text, $m);

        return array_map(static fn ($url) => rtrim($url, '.,;:!?'), $m[0]);
    }
}
