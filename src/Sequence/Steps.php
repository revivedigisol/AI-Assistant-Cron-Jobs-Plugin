<?php

namespace Enle\DailyCron\Sequence;

defined('ABSPATH') || exit;

/**
 * The Idea-to-Market steps this plugin sends, from
 * "Enle_Idea_to_Market_Email_Sequence.docx". Day 0 (the report itself) is
 * sent by the ai-assistant plugin.
 */
final class Steps
{
    public const DAY3  = 'day3';
    public const DAY7  = 'day7';
    public const DAY14 = 'day14';

    /** @return array<string,int> Step key → day offset, in sending order. */
    public static function offsets(): array
    {
        return array(
            self::DAY3  => 3,
            self::DAY7  => 7,
            self::DAY14 => 14,
        );
    }

    public static function exists(string $step): bool
    {
        return isset(self::offsets()[$step]);
    }

    public static function label(string $step): string
    {
        return match ($step) {
            self::DAY3  => 'Day 3: Here\'s what matters most',
            self::DAY7  => 'Day 7: Here\'s how to test it',
            self::DAY14 => 'Day 14: What\'s your next step?',
            default     => $step,
        };
    }

    /**
     * What this step's email must do. Goes into the prompt as the brief.
     *
     * @param array<string,string> $links programme_url, booking_url, programme_price.
     */
    public static function brief(string $step, array $links): string
    {
        return match ($step) {
            self::DAY3 => <<<TXT
Step: Day 3. Purpose: engagement. Main message: "Here's what matters most for your idea."
- Pick ONE important finding or unresolved commercial question from this founder's report, and name where in the report it comes from (for example the section heading).
- Explain in plain words why it matters for the customers the report names.
- Give ONE small, practical action they can take this week, drawn from the report.
- Invite them to try it and reply with how it went or with a question.
- Do NOT mention any price, any paid service, Market Validation, or a consultation. No links at all.
TXT,
            self::DAY7 => <<<TXT
Step: Day 7. Purpose: consideration. Main message: "Here's how you can test this with the market."
- Open by thanking them for exploring their idea with Enle's Idea Validation Assistant.
- Name one strength of their idea from the report, then the key commercial question still open (usually whether the right buyers will pay a price that works).
- Give one concrete thing they can do this week from their report (a different one from a Day 3 action where possible).
- Explain the difference between evaluating an idea on paper and seeing how real potential buyers respond.
- Introduce Enle's Market Validation programme by the outcome for them: over 2–4 weeks Enle puts their offer in front of real potential buyers with a simple test page built around a message drawn from their report, so they see who responds, which message works, and whether people show real intent to buy, and can decide to go ahead, adjust or rethink before spending on materials or stock.
- State the price exactly as "{$links['programme_price']}" and give this link on its own line: {$links['programme_url']}
- Offer a free consultation as the alternative, with this link on its own line: {$links['booking_url']} — or simply replying with what they're most unsure about.
- End warmly and without pressure.
TXT,
            self::DAY14 => <<<TXT
Step: Day 14. Purpose: conversion. Main message: "What would be most useful for you as a next step?"
- Acknowledge that it has been a couple of weeks since their report and that people move at different speeds.
- Ask which of these sounds most like them, as a numbered list written exactly in this form:
1. Still exploring. (then a short clause that fits their idea)
2. Ready to test. (then a short clause: seeing whether real buyers will pay their price)
3. Not sure what to do next.
- Tell them they can just reply with a number, or say what they're most unsure about. There's no wrong answer.
- Point to one clear milestone or target from their report that shows when they're ready to go further.
- Offer a free consultation with this link on its own line: {$links['booking_url']}
- Mention Enle's Market Validation programme ONCE, only for people at number 2, with this link: {$links['programme_url']}. Do not state the price.
- Keep it warm and low-pressure.
TXT,
            default => '',
        };
    }
}
