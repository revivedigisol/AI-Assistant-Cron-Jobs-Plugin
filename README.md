# Enle Daily Cron: Idea-to-Market Follow-ups

A WordPress plugin for **enle.org only** (site 1, "Enle Business Hub"). Once a day it sends each Idea Validation lead the next email in Enle's Idea-to-Market sequence. Claude writes each email from that lead's own Validation Report, and the plugin sends it automatically over SMTP.

| Day | Email | Sent by |
|---|---|---|
| 0 | The report | ai-assistant (already live) |
| 3 | One finding from their report + one small action. No price, no pitch, no links | this plugin |
| 7 | How to test it with real buyers. Market Validation, £1,000, link + consultation link | this plugin |
| 14 | "Which sounds like you? 1 / 2 / 3". Reply or book a consultation | this plugin |

Specs: `Enle_Idea_to_Market_Email_Sequence.docx` and `Sequence Emails.docx`. Design and decisions: [`knowledge-base/architecture.md`](knowledge-base/architecture.md).

## Requirements

- WordPress 6.5+, PHP 8.1+. No Composer packages at runtime; deploy by copying the folder.
- **AI Assistant 1.10.0+** on the same site. It fires `enle_idea_validation_report_ready`, which adds the lead.
- The Claude API key saved in AI Assistant settings (option `ai_assistant_claude_api_key`), or the constant `ENLE_DC_CLAUDE_API_KEY` in `wp-config.php`.
- FluentSMTP (or any working `wp_mail()`). FluentCRM, FluentCart and FluentBooking are read for the stop rules; if one is missing, its rule simply never fires.

## Setup on enle.org

1. Copy the folder to `wp-content/plugins/enle-daily-cron/`. Update AI Assistant to 1.10.0.
2. **Activate on enle.org only** (Sites → enle.org → Plugins). Network activation is refused.
3. FluentCRM tag **"Replied"** (already created on enle.org, ID 37). When a lead replies, the team adds it to their contact, and their emails stop.
4. **Sender:** FluentSMTP's connection is the Gmail account `enlelimited@gmail.com`. Add `contact@enle.org` as a verified "Send mail as" address on that Gmail account (or add a FluentSMTP connection for it). Otherwise Gmail rewrites the From address to enlelimited@gmail.com.
5. AI Assistant → **Idea follow-ups**: tick **Send emails**, leave **Mode = Dry run**, save.
6. No server cron is needed. The plugin runs on WordPress's own WP-Cron, which fires on site visits. Checked on enle.org (2026-11-09): WP-Cron is on (`DISABLE_WP_CRON` not set), the site can reach its own `wp-cron.php`, and no events were overdue. A late run costs nothing: each step can still go out for 7 days after it falls due. If runs ever stop (for example `DISABLE_WP_CRON` gets set), the admin page shows a warning and the team gets a "missed run" alert.
7. After a week of reviewing the previews in the preview inbox, switch **Mode = Live**.

## How it works

- **Enrolment:** each new report adds a lead (new reports only, no backfill). Reports saved to another business's CRM (for example the redgoldtraders context) are ignored. A founder who unsubscribed is never added again. A second report from someone already in the sequence replaces the stored report and keeps their schedule.
- **Daily run** (default 09:00 UTC): for every active lead:
  1. **Stop rules.** If any of these is true, the lead is stopped:
     - unsubscribed, bounced or complained in FluentCRM;
     - a paid FluentCart order for Market Validation (product 2688);
     - a FluentBooking booking for events 27/28 since the report;
     - the "Replied" tag.

     A purchase or booking also emails the team straight away. A purchase or booking also triggers a check within a minute (`fluent_cart/order_paid`, `fluent_booking/after_booking_scheduled`).
  2. **Pick the step.** A step falls due on its calendar day (UTC) and can go out for 7 days after that. If it's later than that, it's skipped. At most one email per lead per run, and never two within 2 days.
  3. **Write.** Claude writes the email (structured JSON output, model `claude-opus-5-5`, refusal fallback on). The plugin then checks it: greeting, sign-off, length, no Markdown/HTML/placeholders, and the step rules (Day 3: no price and no links; Day 7: the price and programme link; Day 14: the three numbered options). Only the configured enle.org links are allowed. If a draft fails, Claude gets one retry with the reasons. If that fails too, nothing is sent and the team is alerted; the next run tries again.
  4. **Send.** `wp_mail()` with From `Monsur at Enle <contact@enle.org>` (the From name and the sign-off name are settings; by default the email is signed with the From name before " at "), Reply-To `contact@enle.org`, `List-Unsubscribe` + one-click, and an unsubscribe link in the footer.
- **Dry run:** emails are written and checked as in live mode but go to the preview inbox with a `[Preview: … to Name <email>]` subject. Each lead's schedule still moves on.
- **Alerts** (to `contact@enle.org` by default): a summary email when a run had failures or the previous run was more than 36 hours earlier, and an "Action needed" email for each purchase or booking.
- **Admin page** (AI Assistant → Idea follow-ups): status, warnings, settings, leads (with a Stop button), and recent activity, including the full text of every email sent.
- **Test the sequence** (same page): comma-separated emails get Day 3, 7 and 14 straight away, from the sample report or a real lead's report. Nothing about any lead changes.

## WP-CLI

```
wp enle-dc status
wp enle-dc run [--force]                      # --force runs even when switched off
wp enle-dc test-lead --email=you@enle.org --days-ago=3
wp enle-dc preview <lead-id> [--step=day7]    # writes with Claude and prints; sends nothing
```

## Security

- `ABSPATH` guard on every file. Admin actions use `manage_options`, a nonce and `admin-post.php`. Settings are whitelisted and clamped, and links must be `https://` on enle.org.
- All SQL goes through `$wpdb->prepare()`. The run lock is an atomic `INSERT IGNORE` into `wp_options`, since enle.org has no object cache.
- The report text comes from a public chat. It goes to Claude as data, never as instructions, and every draft is checked before sending: the model can't add links or email addresses or change the recipient. Output HTML is built only from escaped text.
- Unsubscribe links are signed with an HMAC of the lead id and email under a site secret. GET only shows a confirm button, so mail scanners can't unsubscribe people; POST or one-click unsubscribes.
- Claude API key: read from the existing option, never logged or shown.
- Personal data: finished leads and their log are deleted after 180 days (configurable). `uninstall.php` drops both tables and all options.

## Development

```
composer --working-dir=tools install   # once: PHPUnit into tools/vendor (not deployed)
tools/vendor/bin/phpunit               # 80 tests, no WordPress or database needed
```

Tests use WordPress stand-ins (`tests/Support/wordpress.php`) and fakes for the database, Claude, mail and the lock (`tests/Support/Fakes.php`). The SQL in `src/Storage/` and the admin page are checked by hand on a WordPress site. Every change gets a version bump (`Version:` header and `ENLE_DC_VERSION`) and a `CHANGELOG.md` entry.
