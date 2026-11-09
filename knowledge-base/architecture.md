# Enle Daily Cron: Idea-to-Market Follow-ups — Architecture (v3)

> Status: **built as v1.0.0 (2026-11-09), tested locally, not yet deployed to enle.org.**
> Placeholder knowledge base until the main one is complete. Setup/rollout steps: `../README.md`.
> Sections 1–10 are the design as reviewed; §11 records the decisions made since and how they were built.
> Specs: `Enle_Idea_to_Market_Email_Sequence.docx` (sequence rules) and `Sequence Emails.docx` (sample Day 3/7/14 emails for "Mariam").

---

## 1. What the job does

Once a day, on **enle.org (site 1, "Enle Business Hub") only**, the job sends each Idea Validation lead the next due
email in the Idea-to-Market sequence. Claude writes every email from that lead's own Validation Report, and the job
sends it automatically over SMTP.

| Day | Purpose | Sent by | Paid service | CTA |
|---|---|---|---|---|
| 0 | Here's your report | **ai-assistant (already live)**: `enle_deliver_idea_validation_document()` | None | Review the report |
| 3 | Here's what matters most: one finding + one small action | this plugin | **None** | Try the action / reply |
| 7 | Here's how to test it with real buyers | this plugin | Market Validation, £1,000, `https://enle.org/market-validation/` | Explore the programme / book a consultation |
| 14 | What's your next step? Reply 1, 2 or 3 | this plugin | Once, for leads ready to test | Reply / book a consultation |

Every email is signed "Monsur, Enle Business Hub" and has Reply-To: contact@enle.org (see §6 for the From address).

## 2. Answers to the open questions from v1

| # | Question | Answer |
|---|---|---|
| 1 | What does the job do? | The follow-up sequence above |
| 2 | Which sites? | **enle.org only (blog 1).** Activate on that site only, not network-wide |
| 3 | Write how? Approve first? | Claude writes each email; it **sends automatically** (no approval step) |
| 4 | Send how? | SMTP via `wp_mail()`. FluentSMTP is already configured on enle.org |
| 5 | Day 0 | ai-assistant already sends it when the report is ready; this plugin starts from Day 3 |
| 6 | Failure alerts | Yes (see §8) |

## 3. What I found (read-only, 2026-11-09)

### ai-assistant (local source, v1.9.0 live on enle.org)
- The report job is `enle_run_idea_validation_report_job()` (`chatbot/flows/IdeaValidationFlow.php`). In order, it:
  1. generates the report (`generate_idea_validation_document()` → `title`, `idea_data`, sections, `file_url`)
  2. creates or updates the FluentCRM contact on `https://enle.org` (`enle_create_or_update_idea_validation_lead()`: list 4 "Leads", **tag 99**)
  3. adds a CRM note with the summaries and report link (`enle_save_idea_validation_note()`)
  4. emails the team at contact@enle.org, then **emails the founder the report (Day 0)**
- **The report text is not stored in the database.** It only exists as files in
  `wp-content/uploads/ai-assistant/idea-validation/<name>.html` (+ `.pdf`), linked from the CRM note.
- There are **no hooks** (`do_action`) in that job, so nothing else can find out a report was made.
- Claude API key option `ai_assistant_claude_api_key` is set on enle.org.

### enle.org database (site 1)
| Item | Found | Impact |
|---|---|---|
| FluentCRM list 4 "Leads" | 13 contacts | Where leads land |
| FluentCRM **tag 99** | **Does not exist.** Tags run 13–36; "Idea Validation Stage" is **tag 25**, with 0 contacts | ai-assistant bug: Idea Validation leads aren't tagged, so they can't be picked out in the CRM |
| Idea Validation CRM notes | **2** notes (one has a report link) | Most leads aren't linked to their report |
| Report files | 12 files from 2026-09-28 to 2026-11-07; 5 have `.html`, the rest only `.pdf` | Backfilling old leads is only realistic for the HTML ones |
| "Tracker" from the spec | **Not in the database** | This plugin's table becomes the tracker |
| FluentCart products | "Market Validation" (post 2688, £1,000, GBP, 1 paid order); "Idea Validation" (2673, £500, draft) | Purchase detection |
| FluentBooking events | 27 "Book a Consultation Now", 28 "Market Testing Consultation" | Booking detection; table `wp_fcal_bookings` has `email`, `status`, `start_time` |
| Hooks available | `fluent_cart/order_paid`, `fluent_booking/after_booking_scheduled` | React the same moment, not just at the daily run |
| FluentSMTP | **One Gmail connection, sender `enlelimited@gmail.com`**, logging on, 0 failures in 30 days | Mail won't show as from contact@enle.org (see §6) |
| WP-Cron | Traffic-triggered, UTC, no object cache | No server cron (not possible on this hosting); checked 2026-11-09: WP-Cron on, loopback OK, nothing overdue |

## 4. Proposed architecture

```
 ai-assistant report job ──do_action('enle_idea_validation_report_ready', $ctx)──┐   (small change to ai-assistant)
                                                                              ▼
                                                     Enrolment: insert lead row (status=active, enrolled_at, report)
 fluent_cart/order_paid (product 2688) ──────────────► stop: purchased  → alert team "start onboarding"
 fluent_booking/after_booking_scheduled (27/28) ─────► stop: booked     → alert team
 Unsubscribe link / FluentCRM unsubscribed ──────────► stop: unsubscribed
 Team marks "replied" (FluentCRM tag) ───────────────► stop: replied (a person takes over)

 Daily WP-Cron on enle.org  ── enle_dc_daily ──► Runner
   1. lock (atomic wp_options row)
   2. for each active lead whose next step is due (batch, time budget):
        a. re-check stop rules from the DB (purchase / booking / unsubscribed / replied tag)
        b. pick the step: the latest due, unsent step (never 2 emails in one run; skipping an old step is fine)
        c. Claude writes {subject, body} from the report + step brief
        d. validate (rules in §5); one retry; else mark failed and alert
        e. wp_mail() → log result; set stepN_sent_at
   3. Day 14 sent → status=completed
   4. release lock, write run summary, alert if anything failed
```

### Data (custom tables on site 1, created with `dbDelta`)
- `{prefix}enle_dc_leads`: id, email (unique per active sequence), name, crm_contact_id, report_title, report_url,
  report_json (the parsed report sections), enrolled_at, status (`active|paused|stopped|completed`), stop_reason,
  day3_at / day7_at / day14_at (sent timestamps), skipped_steps, unsubscribe_token (hashed), created_at, updated_at.
- `{prefix}enle_dc_log`: lead_id, step, action (`sent|skipped|failed|stopped|paused`), detail, subject, created_at.
  The sent email body is kept so the team can see exactly what each lead received.

### Change needed in ai-assistant (needs its own version bump and CHANGELOG entry)
At the end of `enle_run_idea_validation_report_job()`, fire one action with the lead, CRM contact id, title,
`file_url` and the parsed report sections. The cron plugin listens for it; ai-assistant doesn't depend on the cron
plugin. I'd also fix **tag 99 → 25** ("Idea Validation Stage") while there.

## 5. Writing the emails (Claude)
- Key: reuse `ai_assistant_claude_api_key` (read-only); the model can be set in the plugin's settings.
- Prompt per step = the sequence rules + that step's brief (purpose / content / CTA from the spec) + the lead's report
  sections + the Mariam samples as style examples. The model must quote or use **real points from that report**
  and must not invent facts.
- Output must be JSON `{subject, body}`. Code checks before sending:
  - the lead's first name is used; signed "Monsur"; length limits
  - links allowed: only `enle.org/market-validation/`, the consultation link and the unsubscribe link
  - Day 3: no price, no "Market Validation"; Day 7: the £1,000 price and programme link; Day 14: the three numbered options
  - plain text in, escaped HTML out (no raw model HTML)
- If the check fails twice, don't send; log it and alert. Never fall back to a generic email.

## 6. Sending (SMTP)
- `wp_mail()` through FluentSMTP. Headers: `From: Monsur at Enle <contact@enle.org>`, `Reply-To: contact@enle.org`,
  `List-Unsubscribe`.
- **Issue:** FluentSMTP's only connection is Gmail as `enlelimited@gmail.com`. Gmail rewrites the From address
  unless contact@enle.org is a verified "Send mail as" alias on that account. Otherwise emails arrive as from
  enlelimited@gmail.com. Fix by adding the alias, or a FluentSMTP connection for contact@enle.org.
- Each email has a one-click **unsubscribe** link (signed token → stops the sequence and sets the FluentCRM contact
  to unsubscribed). These are sales emails, so UK PECR/GDPR requires an opt-out.

## 7. Stop / pause rules (from the spec)
| Event | Detected by | Effect |
|---|---|---|
| Buys Market Validation | `fluent_cart/order_paid` + daily DB check of `wp_fct_orders` (paid, product 2688, same email) | **Stop**; alert the team to start onboarding |
| Books a consultation | `fluent_booking/after_booking_scheduled` + daily check of `wp_fcal_bookings` (events 27/28, same email) | **Stop** (decided 2026-11-09); alert the team |
| Replies | The team adds the FluentCRM tag "Replied" | **Stop**; a person handles it |
| Unsubscribes | Our link, or FluentCRM status ≠ subscribed | **Stop** |
| No engagement | — | Next email on schedule |

## 8. Failure alerts
- Any failed generation or send, a lead held back, or an unexpected error → **one summary email per run** to
  contact@enle.org (configurable), plus the log.
- **Missed-run watchdog:** if the last successful run is over 36 hours old, the next page load schedules a run and
  the next alert says so. A WP-admin notice also shows on the plugin page.
- Purchases and bookings send an immediate "action needed" email to the team.

## 9. Security
- `ABSPATH` guards; no REST/AJAX except the public **unsubscribe** endpoint (HMAC-signed token, rate-limited, no
  personal data shown).
- Admin page + actions: `manage_options`, nonce, `admin-post.php`. Settings whitelisted and clamped.
- All SQL through `$wpdb->prepare()`; report text treated as untrusted (it's founder input passed through an AI).
- Prompt-injection guard: report text goes in as data, with output validation (§5) as the backstop. The model can't
  add links or change the recipient.
- Personal data: kept only as long as the sequence needs it (configurable); `uninstall.php` drops the tables.

## 10. Tests
- **Unit (PHPUnit, WP stubs, fake Claude client, fake mailer):** step selection (due / late / skip / once per run),
  each stop rule, output validation (Day 3 with a price fails, foreign link fails, missing name fails), retry then
  fail → alert, unsubscribe token (valid / tampered / expired), lock, enrolment de-duplication.
- **Integration (Local site):** fire the enrolment action with the Mariam report, run `wp enle-dc run --dry-run`,
  check the generated emails and log; then a real send to a test inbox.
- **enle.org:** first week in **dry-run mode** (writes and logs emails, sends to contact@enle.org instead of leads),
  then switch on.

## 11. Decisions (2026-11-09) and what was built

| # | Question | Decision | Built as |
|---|---|---|---|
| 1 | Replies | The team adds the FluentCRM tag **"Replied"**; the plugin does not read the mailbox | `FluentSignals::hasRepliedTag()` → stop `replied` |
| 2 | After a booking or a sale | **Stop the sequence for good** (no pause/resume) | `StopRules`: `purchased`, `booked` → stop + "Action needed" email to the team |
| 3 | From address | Add contact@enle.org as a Gmail send-as / FluentSMTP connection (**to do on the Gmail account**) | Headers: From `Monsur at Enle <contact@enle.org>`, Reply-To contact@enle.org |
| 4 | ai-assistant change | Approved | ai-assistant **1.10.0**: `enle_idea_validation_report_ready` action; Idea Validation tag 25 on enle.org |
| 5 | Backfill | **New reports only** | No import; `Enrolment` only handles the action |
| 6 | Run time, consultation link, staging | 09:00 UTC; `https://enle.org/book-an-appointment-now/` (page with the FluentBooking form, HTTP 200); no staging, so **dry run on live** first | Settings defaults |
| 7 | Old scaffold | Deleted and rebuilt from this design | — |
| — | Market Validation link | Spec URL kept: `https://enle.org/market-validation/` (301 → `/product/market-validation/`, 200) | Setting `programme_url` |

Implementation notes that differ from or add to the sketch in §4:
- **Booking = stop, not pause** (decision 2). The `pause` status was dropped.
- **Step timing:** steps fall due on UTC calendar days from the report day; each step has a 7-day window and is
  skipped after it. At most one email per run; when two are due, the later is sent and the earlier skipped; never
  two within 2 days (`StepPicker`).
- **Dry run moves the schedule on.** That way the preview inbox sees the real sequence. Leads previewed in dry run
  don't receive those steps again when switching to live.
- **Claude call:** raw HTTP (`wp_remote_post`), API version `2026-10-21`, model `claude-opus-5-5` at effort `medium`,
  structured output (`json_schema` with `subject`/`body`), `fallbacks: "default"` (beta
  `server-side-fallback-2026-07-01`), system prompt cached. A `refusal` or `max_tokens` stop is an error, never sent.
- **Only enle.org's CRM leads are enrolled.** ai-assistant also validates ideas for client businesses (the
  redgoldtraders context). Filter: `enle_dc_enrol_crm_host`.
- **Purchase/booking hooks only schedule a check** (`enle_dc_sweep`, ~1 minute later). This keeps checkout fast and
  avoids depending on those plugins' hook arguments.
- Code map: `src/Sequence` (steps, picker, stop rules, enrolment), `src/Email` (Claude client, writer + prompt,
  validator, renderer, mailer, unsubscribe), `src/Cron` (runner, scheduler, lock), `src/Storage` (tables, Fluent
  signals), `src/Alerts`, `src/Admin`, `src/Cli`.

Still to do before going live (outside the code):
1. Add contact@enle.org as a "Send mail as" address on the Gmail account FluentSMTP uses.
2. ~~Create the FluentCRM tag "Replied" on enle.org.~~ Done 2026-11-09 (tag ID 37, created through the FluentCRM REST API with AI Assistant's CRM user).
3. Deploy ai-assistant 1.10.0 and this plugin; activate on enle.org only; enable in **dry run**.
4. Review a week of previews, then switch to **Live**.
