# Changelog

All notable changes to Enle Daily Cron. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). Each release bumps both the `Version:` header and `ENLE_DC_VERSION` in `enle-daily-cron.php`.

## [1.0.2] - 2026-11-11

### Fixed
- **The sign-off now follows the sender.** It was always "Monsur", whatever the From name said. Emails are now signed with the new **Sign-off name** setting, or, when that is empty, the From name up to " at " ("Mariam at Enle" signs as "Mariam"). Claude is asked for that name and every draft is checked for it.
- The example founder in Claude's instructions and the sample report is now "Amara", so the examples don't share a name with the sender. 80 tests.

## [1.0.1] - 2026-11-11

### Added
- **Test the sequence** on the admin page: enter comma-separated emails (up to 5), pick the sample report or a lead's real report, and optionally a name to greet. Each address gets the Day 3, Day 7 and Day 14 emails within a minute or two, written by Claude and checked exactly like real ones, with "[Test Day N]" in the subject. Each email is its own WP-Cron event, so the page returns at once. Test sends work even when sending is off, never create or change a lead, and are logged as "test" in Recent activity. 75 tests.

### Changed
- The admin page moved from Settings → Idea follow-ups to **AI Assistant → Idea follow-ups**, next to Prompts and WhatsApp. If AI Assistant's menu isn't there, it falls back to Settings. Links in alert emails and redirects now use `admin.php?page=enle-daily-cron`, which works under either menu.
- Activity rows from test sends are deleted after the retention period like other finished data.

## [1.0.0] - 2026-11-09

### Added
- **Idea-to-Market follow-up emails (Day 3, 7, 14)** for founders who get an Idea Validation Report on enle.org. Claude writes each email from the founder's own report, and the plugin sends it automatically over SMTP once a day.
- Enrolment from AI Assistant's `enle_idea_validation_report_ready` action (AI Assistant 1.10.0+). New reports only; other businesses' leads and unsubscribed founders are ignored.
- Stop rules:
  - Market Validation purchase (FluentCart): stops the sequence and emails the team.
  - Consultation booking (FluentBooking): stops the sequence and emails the team.
  - "Replied" tag (FluentCRM): stops the sequence.
  - Unsubscribed or bounced contact: stops the sequence.
  - Purchases and bookings are also checked within a minute.
- Checks on every drafted email, one retry with reasons, never a generic fallback email.
- Dry-run mode, which sends every email to a preview inbox instead of the lead.
- Signed unsubscribe links with RFC 8058 one-click support.
- Alerts for failed emails and missed daily runs.
- Admin page (Settings → Idea follow-ups) and WP-CLI commands (`wp enle-dc status|run|preview|test-lead`).
- Safety and upkeep:
  - Runs on a single site; network activation is refused.
  - An atomic run lock, a time budget per run, and a follow-up run when the budget is hit.
  - The daily schedule repairs itself if it goes missing.
  - Finished leads are deleted after 180 days.
- 69 unit tests.
