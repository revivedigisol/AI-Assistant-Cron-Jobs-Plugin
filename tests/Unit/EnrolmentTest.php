<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Cli\SampleReport;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Sequence\Enrolment;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Tests\Support\InMemoryLeadStore;
use Enle\DailyCron\Tests\TestCase;

final class EnrolmentTest extends TestCase
{
    /** The payload ai-assistant 1.10.0 sends. */
    private static function payload(array $overrides = array()): array
    {
        $sections                      = SampleReport::sections();
        $sections['executive_summary'] = $sections['summary'];
        unset($sections['summary']);

        return array_merge(array(
            'email'          => 'mariam@example.com',
            'name'           => 'Mariam Bello',
            'crm_contact_id' => 84,
            'crm_url'        => 'https://enle.org',
            'title'          => 'Idea Validation: Handmade Beaded Bags',
            'file_url'       => 'https://enle.org/wp-content/uploads/ai-assistant/idea-validation/bags.html',
            'sections'       => $sections,
        ), $overrides);
    }

    public function testEnrolsANewLead(): void
    {
        $store  = new InMemoryLeadStore();
        $result = (new Enrolment($store))->handle(self::payload(), self::NOW);

        $this->assertSame('enrolled', $result['result']);

        $lead = $store->find($result['lead_id']);
        $this->assertSame('mariam@example.com', $lead->email);
        $this->assertSame(84, $lead->crm_contact_id);
        $this->assertSame(self::NOW, $lead->enrolled_at);
        $this->assertSame(Lead::ACTIVE, $lead->status);
        $this->assertStringContainsString('handmade beaded bags', $lead->report['summary']);
        $this->assertArrayHasKey('roadmap', $lead->report);
        $this->assertSame(array('enrolled'), $store->actions($lead->id));
    }

    public function testIgnoresLeadsSavedToAnotherBusinessesCrm(): void
    {
        $store  = new InMemoryLeadStore();
        $result = (new Enrolment($store))->handle(self::payload(array('crm_url' => 'https://redgoldtraders.enle.org')), self::NOW);

        $this->assertSame('ignored_other_business', $result['result']);
        $this->assertSame(array(), $store->leads);
    }

    public function testIgnoresPayloadsWithoutEmailOrReport(): void
    {
        $enrolment = new Enrolment(new InMemoryLeadStore());

        $this->assertSame('ignored_invalid', $enrolment->handle(self::payload(array('email' => 'not-an-email')), self::NOW)['result']);
        $this->assertSame('ignored_invalid', $enrolment->handle(self::payload(array('sections' => array())), self::NOW)['result']);
        $this->assertSame('ignored_invalid', $enrolment->handle('nonsense', self::NOW)['result']);
    }

    public function testASecondReportKeepsTheScheduleAndReplacesTheReport(): void
    {
        $store     = new InMemoryLeadStore();
        $enrolment = new Enrolment($store);
        $first     = $enrolment->handle(self::payload(), self::NOW - 2 * DAY_IN_SECONDS);

        $second = $enrolment->handle(self::payload(array('title' => 'Idea Validation: Beaded Bags v2')), self::NOW);

        $this->assertSame('updated', $second['result']);
        $this->assertSame($first['lead_id'], $second['lead_id']);
        $this->assertCount(1, $store->leads);

        $lead = $store->find($first['lead_id']);
        $this->assertSame('Idea Validation: Beaded Bags v2', $lead->report_title);
        $this->assertSame(self::NOW - 2 * DAY_IN_SECONDS, $lead->enrolled_at);
    }

    public function testAnUnsubscribedFounderIsNotEnrolledAgain(): void
    {
        $store = new InMemoryLeadStore();
        $store->save(self::lead(20, array('status' => Lead::STOPPED, 'stop_reason' => StopRules::UNSUBSCRIBED)));

        $this->assertSame('ignored_unsubscribed', (new Enrolment($store))->handle(self::payload(), self::NOW)['result']);
        $this->assertCount(1, $store->leads);
    }

    public function testAFinishedFounderWithANewReportStartsAgain(): void
    {
        $store = new InMemoryLeadStore();
        $store->save(self::lead(60, array('status' => Lead::COMPLETED)));

        $this->assertSame('enrolled', (new Enrolment($store))->handle(self::payload(), self::NOW)['result']);
        $this->assertCount(2, $store->leads);
    }

    public function testReportTextIsStrippedOfTagsAndCapped(): void
    {
        $sections            = self::payload()['sections'];
        $sections['verdict'] = '<script>alert(1)</script>' . str_repeat('a', 9000);

        $store  = new InMemoryLeadStore();
        $result = (new Enrolment($store))->handle(self::payload(array('sections' => $sections)), self::NOW);

        $verdict = $store->find($result['lead_id'])->report['verdict'];
        $this->assertStringNotContainsString('<script>', $verdict);
        $this->assertSame(6000, mb_strlen($verdict));
    }

    public function testTheCrmHostRuleCanBeWidenedByFilter(): void
    {
        add_filter('enle_dc_enrol_crm_host', static fn ($allowed, $host) => $allowed || $host === 'innovate.enle.org', 10, 2);

        $result = (new Enrolment(new InMemoryLeadStore()))->handle(self::payload(array('crm_url' => 'https://innovate.enle.org')), self::NOW);

        $this->assertSame('enrolled', $result['result']);
    }
}
