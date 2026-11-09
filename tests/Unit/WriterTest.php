<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Tests\Support\FakeAi;
use Enle\DailyCron\Tests\TestCase;
use WP_Error;

final class WriterTest extends TestCase
{
    public function testAValidReplyIsReturned(): void
    {
        $ai            = new FakeAi();
        $ai->replies[] = self::validDraft(Steps::DAY3);

        $draft = (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY3);

        $this->assertIsArray($draft);
        $this->assertStringStartsWith('Hi Mariam,', $draft['body']);
        $this->assertCount(1, $ai->calls);
    }

    public function testThePromptCarriesTheBriefNameAndReportAsData(): void
    {
        $ai            = new FakeAi();
        $ai->replies[] = self::validDraft(Steps::DAY7);

        (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY7);

        $user = $ai->calls[0]['user'];
        $this->assertStringContainsString('Step: Day 7', $user);
        $this->assertStringContainsString('Founder first name: Mariam', $user);
        $this->assertStringContainsString('"£1,000"', $user);
        $this->assertStringContainsString('https://enle.org/market-validation/', $user);
        $this->assertStringContainsString("<report>\n## Executive summary", $user);
        $this->assertStringContainsString('materials + (hours × your hourly rate) + 20%', $user);

        // The system prompt is identical for every lead and step (prompt caching).
        $this->assertSame(Writer::systemPrompt(), $ai->calls[0]['system']);
        $this->assertStringNotContainsString('Mariam Bello', $ai->calls[0]['system']);
    }

    public function testARejectedDraftIsRetriedOnceWithTheReasons(): void
    {
        $bad           = self::validDraft(Steps::DAY3);
        $bad['body']   = str_replace('Just reply to this email.', 'It costs £1,000.', $bad['body']);
        $ai            = new FakeAi();
        $ai->replies   = array($bad, self::validDraft(Steps::DAY3));

        $draft = (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY3);

        $this->assertIsArray($draft);
        $this->assertCount(2, $ai->calls);
        $this->assertStringContainsString('Your previous draft was rejected', $ai->calls[1]['user']);
        $this->assertStringContainsString('Day 3 must not mention a price', $ai->calls[1]['user']);
    }

    public function testTwoRejectedDraftsMeanNothingIsSent(): void
    {
        $bad         = self::validDraft(Steps::DAY3);
        $bad['body'] = str_replace('Hi Mariam,', 'Hello,', $bad['body']);
        $ai          = new FakeAi();
        $ai->replies = array($bad);

        $draft = (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY3);

        $this->assertInstanceOf(WP_Error::class, $draft);
        $this->assertSame('enle_dc_invalid_draft', $draft->get_error_code());
        $this->assertCount(Writer::ATTEMPTS, $ai->calls);
    }

    public function testAnApiErrorIsPassedOnWithoutRetryingTheDraft(): void
    {
        $ai            = new FakeAi();
        $ai->replies[] = new WP_Error('enle_dc_refusal', 'Claude declined.');

        $draft = (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY3);

        $this->assertInstanceOf(WP_Error::class, $draft);
        $this->assertSame('enle_dc_refusal', $draft->get_error_code());
        $this->assertCount(1, $ai->calls);
    }

    public function testNoNameAsksForHiThere(): void
    {
        $ai            = new FakeAi();
        $ai->replies[] = self::validDraft(Steps::DAY3, 'there');

        $lead = self::lead(3, array('name' => 'Idea Validation Prospect'));
        $this->assertIsArray((new Writer($ai, self::links()))->write($lead, Steps::DAY3));
        $this->assertStringContainsString('(not given; open with "Hi there,")', $ai->calls[0]['user']);
    }

    public function testCrlfAndExtraBlankLinesAreTidied(): void
    {
        $reply         = self::validDraft(Steps::DAY3);
        $reply['body'] = str_replace("\n", "\r\n", $reply['body']) . "\r\n\r\n";
        $ai            = new FakeAi();
        $ai->replies[] = $reply;

        $draft = (new Writer($ai, self::links()))->write(self::lead(), Steps::DAY3);

        $this->assertIsArray($draft);
        $this->assertStringNotContainsString("\r", $draft['body']);
        $this->assertStringEndsWith('Enle Business Hub', $draft['body']);
    }
}
