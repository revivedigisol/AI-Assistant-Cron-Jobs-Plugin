<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Alerts\Alerter;
use Enle\DailyCron\Cron\Runner;
use Enle\DailyCron\Cron\Scheduler;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Settings;
use Enle\DailyCron\Tests\Support\FakeAi;
use Enle\DailyCron\Tests\Support\FakeLock;
use Enle\DailyCron\Tests\Support\FakeMailer;
use Enle\DailyCron\Tests\Support\FakeSignals;
use Enle\DailyCron\Tests\Support\InMemoryLeadStore;
use Enle\DailyCron\Tests\TestCase;
use WP_Error;
use WpStub;

final class RunnerTest extends TestCase
{
    private InMemoryLeadStore $store;
    private FakeSignals $signals;
    private FakeAi $ai;
    private FakeMailer $mailer;
    private FakeMailer $alerts;
    private FakeLock $lock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store   = new InMemoryLeadStore();
        $this->signals = new FakeSignals();
        $this->ai      = new FakeAi();
        $this->mailer  = new FakeMailer();
        $this->alerts  = new FakeMailer();
        $this->lock    = new FakeLock();

        WpStub::$options[Settings::OPTION] = array('enabled' => true, 'mode' => Settings::MODE_LIVE);
    }

    private function runner(?callable $clock = null): Runner
    {
        return new Runner(
            $this->store,
            new StopRules($this->signals),
            new Writer($this->ai, self::links()),
            $this->mailer,
            new Alerter($this->alerts),
            $this->lock,
            $clock,
        );
    }

    private function add(Lead $lead): int
    {
        return $this->store->save($lead);
    }

    public function testDoesNothingWhenSwitchedOff(): void
    {
        WpStub::$options[Settings::OPTION] = array('enabled' => false);
        $this->add(self::lead(3));

        $this->assertSame(array('status' => 'disabled'), $this->runner()->run(false, self::NOW));
        $this->assertSame(array(), $this->mailer->sent);
    }

    public function testDoesNothingWhileAnotherRunHoldsTheLock(): void
    {
        $this->lock->free = false;
        $this->add(self::lead(3));

        $this->assertSame('locked', $this->runner()->run(false, self::NOW)['status']);
        $this->assertSame(array(), $this->mailer->sent);
    }

    public function testSendsTheDueEmailToTheLeadInLiveMode(): void
    {
        $id                = $this->add(self::lead(3));
        $this->ai->replies = array(self::validDraft(Steps::DAY3));

        $summary = $this->runner()->run(false, self::NOW);

        $this->assertSame('ok', $summary['status']);
        $this->assertCount(1, $this->mailer->sent);

        $mail = $this->mailer->sent[0];
        $this->assertSame('mariam@example.com', $mail['to']);
        $this->assertSame('Mariam, the one thing that sets your bags apart', $mail['subject']);
        $this->assertContains('From: Monsur at Enle <contact@enle.org>', $mail['headers']);
        $this->assertContains('Reply-To: contact@enle.org', $mail['headers']);
        $this->assertContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['headers']);
        $this->assertStringContainsString('enle_dc_unsub=' . $id . '.', $mail['html']);

        $lead = $this->store->find($id);
        $this->assertSame(array(Steps::DAY3 => self::NOW), $lead->sent);
        $this->assertSame(array('sent:day3'), $this->store->actions($id));
        $this->assertSame(1, $this->lock->released);
        $this->assertSame(array(), $this->alerts->sent, 'No alert when all went well.');
    }

    public function testDryRunSendsToThePreviewInboxAndStillMovesTheScheduleOn(): void
    {
        WpStub::$options[Settings::OPTION] = array('enabled' => true, 'mode' => Settings::MODE_DRY_RUN, 'preview_email' => 'preview@enle.org');
        $id                = $this->add(self::lead(3));
        $this->ai->replies = array(self::validDraft(Steps::DAY3));

        $this->runner()->run(false, self::NOW);

        $mail = $this->mailer->sent[0];
        $this->assertSame('preview@enle.org', $mail['to']);
        $this->assertStringStartsWith('[Preview: Day 3: Here\'s what matters most to Mariam Bello <mariam@example.com>]', $mail['subject']);
        $this->assertNotContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['headers']);
        $this->assertArrayHasKey(Steps::DAY3, $this->store->find($id)->sent);
        $this->assertSame(array('previewed:day3'), $this->store->actions($id));
    }

    public function testNothingIsSentBeforeTheStepIsDue(): void
    {
        $this->add(self::lead(1));

        $this->runner()->run(false, self::NOW);

        $this->assertSame(array(), $this->ai->calls);
        $this->assertSame(array(), $this->mailer->sent);
    }

    public function testAPurchaseStopsTheSequenceAndAlertsTheTeam(): void
    {
        $id = $this->add(self::lead(7, array('sent' => array(Steps::DAY3 => self::NOW - 4 * DAY_IN_SECONDS))));
        $this->signals->purchased['mariam@example.com'] = true;

        $summary = $this->runner()->run(false, self::NOW);

        $lead = $this->store->find($id);
        $this->assertSame(Lead::STOPPED, $lead->status);
        $this->assertSame(StopRules::PURCHASED, $lead->stop_reason);
        $this->assertSame(array(), $this->mailer->sent);
        $this->assertSame(array(), $this->ai->calls, 'No Claude call for a stopped lead.');
        $this->assertCount(1, $summary['stopped']);

        $this->assertCount(1, $this->alerts->sent);
        $this->assertSame('contact@enle.org', $this->alerts->sent[0]['to']);
        $this->assertStringContainsString('Action needed: Mariam Bello bought Market Validation', $this->alerts->sent[0]['subject']);
        $this->assertStringContainsString('start Market Validation onboarding', $this->alerts->sent[0]['html']);
    }

    public function testABookingAfterTheReportStopsTheSequence(): void
    {
        $lead = self::lead(7);
        $id   = $this->add($lead);
        $this->signals->booked['mariam@example.com'] = $lead->enrolled_at + DAY_IN_SECONDS;

        $this->runner()->run(false, self::NOW);

        $this->assertSame(StopRules::BOOKED, $this->store->find($id)->stop_reason);
        $this->assertStringContainsString('booked a consultation', $this->alerts->sent[0]['subject']);
    }

    public function testABookingFromBeforeTheReportDoesNotStopIt(): void
    {
        $lead = self::lead(3);
        $id   = $this->add($lead);
        $this->signals->booked['mariam@example.com'] = $lead->enrolled_at - 30 * DAY_IN_SECONDS;
        $this->ai->replies = array(self::validDraft(Steps::DAY3));

        $this->runner()->run(false, self::NOW);

        $this->assertTrue($this->store->find($id)->isActive());
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testRepliedTagAndUnsubscribedContactStopWithoutAlert(): void
    {
        $a = $this->add(self::lead(3, array('email' => 'a@example.com')));
        $b = $this->add(self::lead(3, array('email' => 'b@example.com')));
        $this->signals->replied['a@example.com'] = true;
        $this->signals->status['b@example.com']  = 'unsubscribed';

        $this->runner()->run(false, self::NOW);

        $this->assertSame(StopRules::REPLIED, $this->store->find($a)->stop_reason);
        $this->assertSame(StopRules::UNSUBSCRIBED, $this->store->find($b)->stop_reason);
        $this->assertSame(array(), $this->mailer->sent);
        $this->assertSame(array(), $this->alerts->sent);
    }

    public function testAFailedDraftIsLoggedAlertedAndRetriedNextRun(): void
    {
        $id                = $this->add(self::lead(3));
        $this->ai->replies = array(new WP_Error('enle_dc_api', 'Claude API returned HTTP 500'));

        $summary = $this->runner()->run(false, self::NOW);

        $lead = $this->store->find($id);
        $this->assertTrue($lead->isActive());
        $this->assertSame(array(), $lead->sent);
        $this->assertSame(array('failed:day3'), $this->store->actions($id));
        $this->assertCount(1, $summary['failed']);
        $this->assertStringContainsString('1 problem in the daily run', $this->alerts->sent[0]['subject']);
        $this->assertStringContainsString('mariam@example.com', $this->alerts->sent[0]['html']);

        // Next day the same step is tried again.
        $this->ai->replies = array(self::validDraft(Steps::DAY3));
        $this->runner()->run(false, self::NOW + DAY_IN_SECONDS);
        $this->assertArrayHasKey(Steps::DAY3, $this->store->find($id)->sent);
    }

    public function testAMailFailureLeavesTheStepUnsent(): void
    {
        $id                   = $this->add(self::lead(3));
        $this->ai->replies    = array(self::validDraft(Steps::DAY3));
        $this->mailer->result = new WP_Error('enle_dc_mail_failed', 'wp_mail() failed: SMTP connect() failed.');

        $summary = $this->runner()->run(false, self::NOW);

        $this->assertSame(array(), $this->store->find($id)->sent);
        $this->assertSame('wp_mail() failed: SMTP connect() failed.', $summary['failed'][0]['error']);
    }

    public function testDayFourteenCompletesTheSequence(): void
    {
        $id = $this->add(self::lead(14, array('sent' => array(Steps::DAY3 => self::NOW - 11 * DAY_IN_SECONDS, Steps::DAY7 => self::NOW - 7 * DAY_IN_SECONDS))));
        $this->ai->replies = array(self::validDraft(Steps::DAY14));

        $summary = $this->runner()->run(false, self::NOW);

        $this->assertSame(Lead::COMPLETED, $this->store->find($id)->status);
        $this->assertSame(1, $summary['completed']);
    }

    public function testOverdueStepsAreSkippedAndLogged(): void
    {
        $id                = $this->add(self::lead(14));
        $this->ai->replies = array(self::validDraft(Steps::DAY14));

        $this->runner()->run(false, self::NOW);

        $this->assertSame(array('skipped:day3', 'skipped:day7', 'sent:day14', 'completed'), $this->store->actions($id));
    }

    public function testMissedRunsAreReportedToTheTeam(): void
    {
        WpStub::$options[Runner::LAST_RUN_OPTION] = array('started_at' => self::NOW - 3 * DAY_IN_SECONDS);

        $summary = $this->runner()->run(false, self::NOW);

        $this->assertSame(72.0, $summary['hours_since_previous_run']);
        $this->assertCount(1, $this->alerts->sent);
        $this->assertStringContainsString('at least one daily run was missed', $this->alerts->sent[0]['html']);
    }

    public function testTimeBudgetStopsTheRunAndSchedulesAContinuation(): void
    {
        $this->add(self::lead(3, array('email' => 'a@example.com')));
        $this->add(self::lead(3, array('email' => 'b@example.com')));
        $this->ai->replies = array(self::validDraft(Steps::DAY3));

        $ticks  = array(0.0, 0.0, 500.0);
        $clock  = static function () use (&$ticks): float {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        };

        $summary = $this->runner($clock)->run(false, self::NOW);

        $this->assertTrue($summary['more_to_do']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertArrayHasKey(Scheduler::CONTINUE, WpStub::$cron);
    }

    public function testOneBrokenLeadDoesNotStopTheOthers(): void
    {
        $broken = $this->add(self::lead(3, array('email' => 'broken@example.com')));
        $good   = $this->add(self::lead(3, array('email' => 'good@example.com')));

        // A database error while checking the first lead.
        $this->signals->throwFor           = 'broken@example.com';
        $this->ai->replies = array(self::validDraft(Steps::DAY3));

        $summary = $this->runner()->run(false, self::NOW);

        $this->assertArrayHasKey(Steps::DAY3, $this->store->find($good)->sent);
        $this->assertTrue($this->store->find($broken)->isActive());
        $this->assertStringContainsString('Unexpected error', $summary['failed'][0]['error']);
    }

    public function testSweepStopsWithoutSending(): void
    {
        $id = $this->add(self::lead(5));
        $this->signals->purchased['mariam@example.com'] = true;

        $this->assertSame(1, $this->runner()->sweepStops());
        $this->assertSame(StopRules::PURCHASED, $this->store->find($id)->stop_reason);
        $this->assertSame(array(), $this->mailer->sent);
        $this->assertCount(1, $this->alerts->sent);
    }

    public function testTheLastRunIsRecorded(): void
    {
        $this->runner()->run(false, self::NOW);

        $this->assertSame(self::NOW, WpStub::$options[Runner::LAST_RUN_OPTION]['started_at']);
        $this->assertSame(Settings::MODE_LIVE, WpStub::$options[Runner::LAST_RUN_OPTION]['mode']);
    }
}
