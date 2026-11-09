<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Email\Validator;
use Enle\DailyCron\Sequence\Steps;
use Enle\DailyCron\Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDraftsPassForEveryStep(): void
    {
        foreach (array(Steps::DAY3, Steps::DAY7, Steps::DAY14) as $step) {
            $draft = self::validDraft($step);
            $this->assertSame(array(), Validator::check($step, $draft['subject'], $draft['body'], 'Mariam', self::links()), $step);
        }
    }

    public function testDayThreeMustNotPitch(): void
    {
        $draft = self::validDraft(Steps::DAY3);
        $body  = str_replace('Just reply to this email.', 'Our Market Validation programme costs £1,000.', $draft['body']);

        $errors = Validator::check(Steps::DAY3, $draft['subject'], $body, 'Mariam', self::links());

        $this->assertContains('Day 3 must not mention a price, Market Validation or a consultation.', $errors);
    }

    public function testDayThreeHasNoLinks(): void
    {
        $draft = self::validDraft(Steps::DAY3);
        $body  = str_replace('Just reply to this email.', "See:\nhttps://enle.org/book-an-appointment-now/", $draft['body']);

        $this->assertContains('Link not allowed in this email: https://enle.org/book-an-appointment-now/', Validator::check(Steps::DAY3, $draft['subject'], $body, 'Mariam', self::links()));
    }

    public function testForeignLinksAreRejected(): void
    {
        $draft = self::validDraft(Steps::DAY7);
        $body  = str_replace('https://enle.org/book-an-appointment-now/', 'https://evil.example/login', $draft['body']);

        $this->assertContains('Link not allowed in this email: https://evil.example/login', Validator::check(Steps::DAY7, $draft['subject'], $body, 'Mariam', self::links()));
    }

    public function testDaySevenNeedsThePriceAndTheProgrammeLink(): void
    {
        $draft = self::validDraft(Steps::DAY7);
        $body  = str_replace(array('£1,000', 'https://enle.org/market-validation/'), array('a fee', ''), $draft['body']);

        $errors = Validator::check(Steps::DAY7, $draft['subject'], $body, 'Mariam', self::links());

        $this->assertContains('Day 7 must state the programme price exactly as £1,000.', $errors);
        $this->assertContains('Day 7 must include the Market Validation link https://enle.org/market-validation/.', $errors);
    }

    public function testDayFourteenNeedsTheThreeOptionsAndNoPrice(): void
    {
        $draft = self::validDraft(Steps::DAY14);
        $body  = str_replace("3. Not sure what to do next.", 'Or tell me if you are not sure. It costs £1,000.', $draft['body']);

        $errors = Validator::check(Steps::DAY14, $draft['subject'], $body, 'Mariam', self::links());

        $this->assertContains('Day 14 must list the three numbered options (1., 2., 3.) on their own lines.', $errors);
        $this->assertContains('Day 14 must not state a price.', $errors);
    }

    public function testGreetingAndSignOff(): void
    {
        $draft = self::validDraft(Steps::DAY3);
        $body  = str_replace(array('Hi Mariam,', "Monsur\nEnle Business Hub"), array('Dear founder,', 'The Enle team'), $draft['body']);

        $errors = Validator::check(Steps::DAY3, $draft['subject'], $body, 'Mariam', self::links());

        $this->assertContains('The email must open with "Hi Mariam,".', $errors);
        $this->assertContains('The email must end with the sign-off "Warm regards," / "Monsur" / "Enle Business Hub".', $errors);
    }

    public function testNoNameMeansHiThere(): void
    {
        $draft = self::validDraft(Steps::DAY3, 'there');

        $this->assertSame(array(), Validator::check(Steps::DAY3, $draft['subject'], $draft['body'], '', self::links()));
    }

    public function testPlaceholdersHtmlAndMarkdownAreRejected(): void
    {
        $draft = self::validDraft(Steps::DAY3);
        $body  = str_replace('Just reply to this email.', "Just reply, [Name].\n\n**Bold** <b>tag</b>", $draft['body']);

        $errors = Validator::check(Steps::DAY3, $draft['subject'], $body, 'Mariam', self::links());

        $this->assertContains('No HTML tags: write plain text.', $errors);
        $this->assertContains('No placeholders, brackets or template codes such as [Name] or {{…}}.', $errors);
        $this->assertContains('No Markdown (headings, bold or "-" bullets); plain sentences only.', $errors);
    }

    public function testLengthAndSubjectLimits(): void
    {
        $errors = Validator::check(Steps::DAY3, str_repeat('x', 130), "Hi Mariam,\n\nToo short.\n\nWarm regards,\nMonsur\nEnle Business Hub", 'Mariam', self::links());

        $this->assertContains('The subject must be one line of at most 120 characters.', $errors);
        $this->assertNotEmpty(array_filter($errors, static fn ($e) => str_starts_with($e, 'The body must be')));
    }

    public function testEmailAddressesInTheBodyAreRejected(): void
    {
        $draft = self::validDraft(Steps::DAY3);
        $body  = str_replace('Just reply to this email.', 'Write to someone@gmail.com instead.', $draft['body']);

        $this->assertContains('No email addresses in the body (replies go to the Reply-To address).', Validator::check(Steps::DAY3, $draft['subject'], $body, 'Mariam', self::links()));
    }
}
