<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Sequence\StepPicker;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Tests\TestCase;

final class StepPickerTest extends TestCase
{
    public function testNothingIsDueBeforeDayThree(): void
    {
        $decision = StepPicker::decide(self::lead(2), self::NOW);

        $this->assertNull($decision['send']);
        $this->assertFalse($decision['complete']);
        $this->assertSame('not_due_yet', $decision['reason']);
    }

    public function testDayThreeGoesOutOnTheThirdCalendarDayWhateverTimeTheReportWasMade(): void
    {
        // Report at 15:00 three days ago; the 09:00 run today is Day 3.
        $this->assertSame(Steps::DAY3, StepPicker::decide(self::lead(3), self::NOW)['send']);
    }

    public function testDaySevenAfterDayThreeWasSent(): void
    {
        $lead       = self::lead(7);
        $lead->sent = array(Steps::DAY3 => self::NOW - 4 * DAY_IN_SECONDS);

        $this->assertSame(Steps::DAY7, StepPicker::decide($lead, self::NOW)['send']);
    }

    public function testWhenTwoStepsAreDueTheLaterOneIsSentAndTheEarlierSkipped(): void
    {
        // The cron was off from Day 3 to Day 8: Day 3 is still in its window, but Day 7 is due too.
        $decision = StepPicker::decide(self::lead(8), self::NOW);

        $this->assertSame(Steps::DAY7, $decision['send']);
        $this->assertSame(array(Steps::DAY3), $decision['skip']);
    }

    public function testAStepPastItsWindowIsSkippedNotSentLate(): void
    {
        // Mariam's case from the spec: no Day 3, and Day 7's window (7–13) has passed by Day 14.
        $decision = StepPicker::decide(self::lead(14), self::NOW);

        $this->assertSame(Steps::DAY14, $decision['send']);
        $this->assertSame(array(Steps::DAY3, Steps::DAY7), $decision['skip']);
    }

    public function testEverythingExpiredCompletesWithoutSending(): void
    {
        $decision = StepPicker::decide(self::lead(40), self::NOW);

        $this->assertNull($decision['send']);
        $this->assertSame(array(Steps::DAY3, Steps::DAY7, Steps::DAY14), $decision['skip']);
        $this->assertTrue($decision['complete']);
    }

    public function testNoTwoEmailsWithinTwoDays(): void
    {
        $lead       = self::lead(7);
        $lead->sent = array(Steps::DAY3 => self::NOW - DAY_IN_SECONDS); // Day 3 went out late, yesterday.

        $decision = StepPicker::decide($lead, self::NOW);

        $this->assertNull($decision['send']);
        $this->assertFalse($decision['complete']);
        $this->assertSame('too_soon_after_last_email', $decision['reason']);
    }

    public function testFinishedSequenceIsComplete(): void
    {
        $lead       = self::lead(20);
        $lead->sent = array(Steps::DAY3 => 1, Steps::DAY7 => 2, Steps::DAY14 => 3);

        $this->assertTrue(StepPicker::decide($lead, self::NOW)['complete']);
        $this->assertSame('', StepPicker::nextStep($lead));
        $this->assertSame(0, StepPicker::nextDueAt($lead));
    }

    public function testNextDueAtIsMidnightOfTheStepDay(): void
    {
        $lead = self::lead(1);

        $this->assertSame(Steps::DAY3, StepPicker::nextStep($lead));
        $this->assertSame(0, StepPicker::nextDueAt($lead) % DAY_IN_SECONDS);
        $this->assertSame(gmdate('Y-m-d', self::NOW + 2 * DAY_IN_SECONDS), gmdate('Y-m-d', StepPicker::nextDueAt($lead)));
    }
}
