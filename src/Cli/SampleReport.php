<?php

namespace Enle\DailyCron\Cli;

defined('ABSPATH') || exit;

/**
 * A short sample report, shaped like ai-assistant's report sections, built
 * from the details quoted in "Sequence Emails.docx". Used by
 * `wp enle-dc test-lead` and by the tests.
 */
final class SampleReport
{
    /** @return array<string,string> */
    public static function sections(): array
    {
        return array(
            'summary'     => 'Mariam makes handmade beaded bags and wants to sell them made to order. The craft is strong; the open question is whether the right buyers will pay a price that makes the work worthwhile.',
            'offer'       => 'Made-to-order beaded bags matched exactly to the colour of a customer\'s outfit fabric, for events.',
            'icp'         => 'First customers: women who go to events often and usually have a date 2–6 weeks away. Second group: bridal parties, where one order can be 4–8 bags.',
            'positioning' => 'Positioning and message: "handmade" alone won\'t set you apart. Exact colour matching to a customer\'s fabric will. Suggested line: "Send me your fabric. I\'ll bead you a bag that matches it."',
            'messaging'   => 'Lead with the outfit match, not the craft. Show one finished bag with its price.',
            'roadmap'     => "Next 30 days: time yourself making one bag start to finish, then set a floor price with the formula materials + (hours × your hourly rate) + 20%. Post one finished bag with its price and message five women outside close friends and family who have an event coming up; ask for a deposit. Count deposits, not likes.\nGuardrails and milestones: five deposits from people who aren't close friends or family, at or above your floor price. That's when the made-to-order line is ready to start.",
            'verdict'     => 'Promising, with pricing untested. Test willingness to pay before buying materials in bulk.',
        );
    }
}
