<?php

namespace Enle\DailyCron\Tests;

use Enle\DailyCron\Cli\SampleReport;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Sequence\Steps;
use PHPUnit\Framework\TestCase as BaseTestCase;
use WpStub;

require_once __DIR__ . '/Support/Fakes.php';

abstract class TestCase extends BaseTestCase
{
    /** 2026-11-09 09:00 UTC, a run time. */
    protected const NOW = 1794214800;

    protected function setUp(): void
    {
        parent::setUp();
        WpStub::reset();
    }

    /** @return array{programme_url:string,booking_url:string,programme_price:string} */
    protected static function links(): array
    {
        return array(
            'programme_url'   => 'https://enle.org/market-validation/',
            'booking_url'     => 'https://enle.org/book-an-appointment-now/',
            'programme_price' => '£1,000',
        );
    }

    protected static function lead(int $days_ago = 3, array $overrides = array()): Lead
    {
        $lead = new Lead(
            id: 0,
            email: 'mariam@example.com',
            name: 'Mariam Bello',
            crm_contact_id: 84,
            report_title: 'Idea Validation: Handmade Beaded Bags',
            report_url: 'https://enle.org/wp-content/uploads/ai-assistant/idea-validation/bags.html',
            report: SampleReport::sections(),
            // Mid-afternoon on the report day.
            enrolled_at: self::NOW - $days_ago * DAY_IN_SECONDS + 6 * HOUR_IN_SECONDS,
        );

        foreach ($overrides as $key => $value) {
            $lead->{$key} = $value;
        }

        return $lead;
    }

    /**
     * A draft that passes every check for the step.
     *
     * @return array{subject:string,body:string}
     */
    protected static function validDraft(string $step, string $name = 'Mariam'): array
    {
        $filler = 'Your report names women who go to events often as your first customers, and they usually have a date a few weeks away. '
            . 'They are not just buying a bag; they are buying one that matches their outfit, which is worth more to them. ';

        $middle = match ($step) {
            Steps::DAY3 => $filler . "Here's one small thing to try this week: post one finished bag with its price and ask five women outside your close friends whether they'd pay a deposit.\n\nIf you try it, I'd love to hear how people respond. Just reply to this email.",
            Steps::DAY7 => $filler . "Enle's Market Validation programme puts your offer in front of real buyers over 2–4 weeks, so you can see who responds before you spend on stock.\n\nThe programme costs £1,000, and you can get started here:\nhttps://enle.org/market-validation/\n\nNot sure yet? Book a free consultation:\nhttps://enle.org/book-an-appointment-now/",
            Steps::DAY14 => $filler . "Which of these sounds most like you right now?\n\n1. Still exploring. I'm working on samples and pricing.\n2. Ready to test. I'd like to see whether real buyers will pay my price.\n3. Not sure what to do next.\n\nJust reply with a number. If you'd rather talk it through, book a free consultation:\nhttps://enle.org/book-an-appointment-now/",
            default => $filler,
        };

        return array(
            'subject' => $name . ', the one thing that sets your bags apart',
            'body'    => "Hi {$name},\n\n" . $middle . "\n\nWarm regards,\nMonsur\nEnle Business Hub",
        );
    }
}
