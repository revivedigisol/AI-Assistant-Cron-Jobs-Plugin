<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Sequence\TestSend;
use Enle\DailyCron\Settings;
use Enle\DailyCron\Tests\Support\FakeAi;
use Enle\DailyCron\Tests\Support\FakeMailer;
use Enle\DailyCron\Tests\Support\InMemoryLeadStore;
use Enle\DailyCron\Tests\TestCase;
use WP_Error;
use WpStub;

final class TestSendTest extends TestCase
{
    private InMemoryLeadStore $store;
    private FakeAi $ai;
    private FakeMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store  = new InMemoryLeadStore();
        $this->ai     = new FakeAi();
        $this->mailer = new FakeMailer();
    }

    private function tester(): TestSend
    {
        return new TestSend(new Writer($this->ai, self::links()), $this->mailer, $this->store);
    }

    public function testParsesCommaSeparatedEmailsAndReportsInvalidOnes(): void
    {
        $parsed = TestSend::parseEmails(" a@example.com, not-an-email ,b@example.com;A@example.com\nc@example.com,, ");

        $this->assertSame(array('a@example.com', 'b@example.com', 'c@example.com'), $parsed['valid']);
        $this->assertSame(array('not-an-email'), $parsed['invalid']);
        $this->assertSame(0, $parsed['dropped']);
    }

    public function testCapsTheNumberOfAddresses(): void
    {
        $raw    = implode(',', array_map(static fn (int $i): string => "t{$i}@example.com", range(1, TestSend::MAX_RECIPIENTS + 2)));
        $parsed = TestSend::parseEmails($raw);

        $this->assertCount(TestSend::MAX_RECIPIENTS, $parsed['valid']);
        $this->assertSame(2, $parsed['dropped']);
    }

    public function testSendsTheStepToTheTestAddressWithATestSubject(): void
    {
        WpStub::$options[Settings::OPTION] = array('enabled' => false); // Test sends ignore the switch.
        $this->ai->replies = array(self::validDraft(Steps::DAY7, 'there'));

        $result = $this->tester()->send('me@example.com', Steps::DAY7, '', TestSend::SAMPLE);

        $this->assertTrue($result);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('me@example.com', $this->mailer->sent[0]['to']);
        $this->assertStringStartsWith('[Test Day 7] ', $this->mailer->sent[0]['subject']);
        $this->assertContains('Reply-To: contact@enle.org', $this->mailer->sent[0]['headers']);
        $this->assertSame(array(), $this->store->recent(10), 'No lead is created.');
        $this->assertSame('test_sent', $this->store->logRows[0]['action']);
        $this->assertSame(0, $this->store->logRows[0]['lead_id']);
    }

    public function testUsesTheChosenLeadsReportAndName(): void
    {
        $id   = $this->store->save(self::lead(3, array('report_title' => 'Idea Validation: Real Report')));
        $lead = $this->tester()->lead('me@example.com', '', $id);

        $this->assertSame('me@example.com', $lead->email);
        $this->assertSame('Mariam Bello', $lead->name);
        $this->assertSame('Idea Validation: Real Report', $lead->report_title);
        $this->assertSame(0, $lead->id);
    }

    public function testGreetAsOverridesTheName(): void
    {
        $this->assertSame('Roqeebah', $this->tester()->lead('me@example.com', 'Roqeebah', TestSend::SAMPLE)->name);
        $this->assertSame('', $this->tester()->lead('me@example.com', '', TestSend::SAMPLE)->name);
    }

    public function testAFailedDraftIsLoggedAndNothingIsSent(): void
    {
        $this->ai->replies = array(new WP_Error('enle_dc_api', 'Claude is down.'));

        $result = $this->tester()->send('me@example.com', Steps::DAY3, '', TestSend::SAMPLE);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(array(), $this->mailer->sent);
        $this->assertSame('test_failed', $this->store->logRows[0]['action']);
    }
}
