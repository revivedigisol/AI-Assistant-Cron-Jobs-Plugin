<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Email\Validator;
use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Settings;
use Enle\DailyCron\Tests\Support\FakeAi;
use Enle\DailyCron\Tests\TestCase;
use WpStub;

final class SignoffTest extends TestCase
{
    public function testDefaultsToMonsur(): void
    {
        $this->assertSame('Monsur', Settings::signoffName());
    }

    public function testFollowsTheFromName(): void
    {
        WpStub::$options[Settings::OPTION] = array('from_name' => 'Mariam at Enle');
        $this->assertSame('Mariam', Settings::signoffName());

        WpStub::$options[Settings::OPTION] = array('from_name' => 'Mariam');
        $this->assertSame('Mariam', Settings::signoffName());
    }

    public function testTheSignoffSettingWins(): void
    {
        WpStub::$options[Settings::OPTION] = array('from_name' => 'Enle Business Hub', 'signoff_name' => 'Mariam Bello');

        $this->assertSame('Mariam Bello', Settings::signoffName());
    }

    public function testWriterAsksForAndChecksTheConfiguredName(): void
    {
        $ai          = new FakeAi();
        $draft       = self::validDraft(Steps::DAY3);
        $draft['body'] = str_replace("Monsur\nEnle Business Hub", "Mariam\nEnle Business Hub", $draft['body']);
        $ai->replies = array($draft);

        $result = (new Writer($ai, self::links(), 'Mariam'))->write(self::lead(), Steps::DAY3);

        $this->assertIsArray($result);
        $this->assertStringContainsString("Warm regards,\nMariam\nEnle Business Hub", $ai->calls[0]['system']);
        $this->assertStringNotContainsString('Monsur', $ai->calls[0]['system']);
    }

    public function testAnOldMonsurSignoffIsRejectedOnceTheNameChanges(): void
    {
        $draft  = self::validDraft(Steps::DAY3);
        $errors = Validator::check(Steps::DAY3, $draft['subject'], $draft['body'], 'Mariam', self::links(), 'Amara');

        $this->assertContains('The email must end with the sign-off "Warm regards," / "Amara" / "Enle Business Hub".', $errors);
    }
}
