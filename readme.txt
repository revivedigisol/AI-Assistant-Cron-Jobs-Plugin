=== Enle Daily Cron: Idea-to-Market Follow-ups ===
Contributors: enle
Tags: email sequence, follow-up, cron, fluentcrm, claude
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: Proprietary
Private plugin for enle.org (site 1, "Enle Business Hub") only.

Sends Idea Validation leads the Day 3, 7 and 14 emails of Enle's Idea-to-Market sequence. Claude writes each email from the lead's own report.

== Description ==

When AI Assistant (1.10.0+) delivers an Idea Validation Report, it fires `enle_idea_validation_report_ready`, and this plugin adds the founder to the sequence. Once a day, at 09:00 UTC by default, it sends each active lead the next email:

* **Day 3:** one finding from their report and one small action. No price, no pitch, no links.
* **Day 7:** how to test the idea with real buyers. Market Validation (£1,000), with the programme link and the consultation link.
* **Day 14:** "Which sounds like you? 1 / 2 / 3", asking them to reply or book a consultation.

The sequence stops for good when the lead:

* buys Market Validation (FluentCart), or
* books a consultation (FluentBooking), or
* gets the FluentCRM tag "Replied", or
* unsubscribes.

A purchase or a booking also emails the team.

Every draft is checked before it is sent: greeting, sign-off, length, the rules for that step, and enle.org links only. A draft that fails gets one retry; if that fails too, nothing is sent and the team gets an alert.

The plugin uses WordPress's built-in WP-Cron, which runs when the site receives requests. No server cron is needed: enle.org receives requests at least every 30 minutes. Each step can go out for up to 7 days after it is due, so a late run loses nothing.

The full documentation is in README.md, and the design and the decisions behind it are in knowledge-base/architecture.md.

== Installation ==

1. Copy the `enle-daily-cron` folder to `wp-content/plugins/`. Update AI Assistant to 1.10.0 or later.
2. Activate the plugin on enle.org only. Network activation is refused.
3. Make sure FluentSMTP can send as contact@enle.org (a Gmail "Send mail as" address on enlelimited@gmail.com).
4. Go to AI Assistant → Idea follow-ups, tick "Send emails", leave Mode on "Dry run", and save.
5. After a week of reviewing the previews sent to contact@enle.org, switch Mode to "Live".

== Frequently Asked Questions ==

= Does it read replies from the mailbox? =

No. When a lead replies, the team adds the FluentCRM tag "Replied" (tag ID 37 on enle.org) to their contact, and the lead's emails stop.

= What happens in dry run? =

Emails are written and checked exactly as in live mode, but they all go to the preview inbox instead of the lead. Each lead's schedule still moves forward.

= Where is the Claude API key? =

The plugin reads the key AI Assistant already stores (option `ai_assistant_claude_api_key`). To use a different key, define `ENLE_DC_CLAUDE_API_KEY` in wp-config.php.

== Changelog ==

= 1.0.1 - 2026-11-11 =
* The admin page is now under AI Assistant → Idea follow-ups.

= 1.0.0 - 2026-11-09 =
* First release. See CHANGELOG.md.
