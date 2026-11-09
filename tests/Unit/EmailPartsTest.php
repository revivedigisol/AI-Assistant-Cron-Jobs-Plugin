<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Cron\Scheduler;
use Enle\DailyCron\Domain\Lead;
use Enle\DailyCron\Email\Renderer;
use Enle\DailyCron\Email\Unsubscribe;
use Enle\DailyCron\Email\WpMailer;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Settings;
use Enle\DailyCron\Tests\Support\FakeSignals;
use Enle\DailyCron\Tests\Support\InMemoryLeadStore;
use Enle\DailyCron\Tests\TestCase;
use WP_Error;
use WpStub;

/** Renderer, unsubscribe tokens, mailer, settings and scheduling. */
final class EmailPartsTest extends TestCase
{
    public function testRendererEscapesEverythingAndLinksOnlyEnle(): void
    {
        $html = Renderer::html("Hi <Mariam> & co,\n\nGo here:\nhttps://enle.org/market-validation/.\nNot here: https://evil.example/x", 'https://enle.org/?enle_dc_unsub=1.abc');

        $this->assertStringContainsString('Hi &lt;Mariam&gt; &amp; co,', $html);
        $this->assertStringContainsString('<a href="https://enle.org/market-validation/" style="color:#0b5cad;">https://enle.org/market-validation/</a>.', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Unsubscribe from these emails', $html);
        $this->assertSame(2, substr_count($html, '<p style="margin:0 0 16px 0;">'));
    }

    public function testUnsubscribeTokensOnlyWorkForTheirOwnLead(): void
    {
        $store = new InMemoryLeadStore();
        $id    = $store->save(self::lead());
        $other = $store->save(self::lead(3, array('email' => 'other@example.com')));
        $unsub = new Unsubscribe($store, new FakeSignals());

        $lead  = $store->find($id);
        $token = $id . '.' . Unsubscribe::signature($lead);

        $this->assertStringContainsString('enle_dc_unsub=' . rawurlencode($token), Unsubscribe::url($lead));
        $this->assertSame($id, $unsub->leadForToken($token)?->id);
        $this->assertNull($unsub->leadForToken($other . '.' . Unsubscribe::signature($lead)), 'Another lead\'s id with this signature');
        $this->assertNull($unsub->leadForToken($id . '.' . str_repeat('0', 32)), 'Forged signature');
        $this->assertNull($unsub->leadForToken('1 OR 1=1'), 'Malformed');
        $this->assertNull($unsub->leadForToken('999.' . Unsubscribe::signature($lead)), 'Unknown lead');
    }

    public function testUnsubscribingStopsTheLeadAndTheCrmContact(): void
    {
        $store   = new InMemoryLeadStore();
        $signals = new FakeSignals();
        $id      = $store->save(self::lead());

        $unsub = new Unsubscribe($store, $signals);
        $unsub->unsubscribe($store->find($id));
        $unsub->unsubscribe($store->find($id)); // Twice: no second log entry.

        $lead = $store->find($id);
        $this->assertSame(Lead::STOPPED, $lead->status);
        $this->assertSame(StopRules::UNSUBSCRIBED, $lead->stop_reason);
        $this->assertSame(array('mariam@example.com'), $signals->unsubscribed);
        $this->assertSame(array('stopped'), $store->actions($id));
    }

    public function testWpMailerReportsWhySendingFailed(): void
    {
        WpStub::$mail_result = false;

        $result = (new WpMailer())->send('a@example.com', 'S', '<p>x</p>', array('Reply-To: contact@enle.org'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('wp_mail() failed: SMTP connect() failed.', $result->get_error_message());
        $this->assertSame(array('Content-Type: text/html; charset=UTF-8', 'Reply-To: contact@enle.org'), WpStub::$mails[0]['headers']);
        $this->assertSame(array(), WpStub::$filters['wp_mail_failed'] ?? array(), 'The capture hook is removed again.');
    }

    public function testSettingsAreWhitelistedAndClamped(): void
    {
        $s = Settings::sanitize(array(
            'enabled'           => '1',
            'mode'              => 'yolo',
            'run_hour'          => '99',
            'alert_email'       => 'not-an-email',
            'programme_url'     => 'https://evil.example/pay',
            'booking_url'       => 'http://enle.org/book/',
            'model'             => 'gpt-5; DROP TABLE',
            'booking_event_ids' => '27, 28, x, -3, 27',
            'retention_days'    => '5',
            'unknown'           => 'ignored',
        ));

        $this->assertTrue($s['enabled']);
        $this->assertSame(Settings::MODE_DRY_RUN, $s['mode']);
        $this->assertSame(23, $s['run_hour']);
        $this->assertSame('contact@enle.org', $s['alert_email']);
        $this->assertSame('https://enle.org/market-validation/', $s['programme_url']);
        $this->assertSame('https://enle.org/book-an-appointment-now/', $s['booking_url'], 'Plain http is refused.');
        $this->assertSame('claude-opus-5-5', $s['model']);
        $this->assertSame(array(27, 28), $s['booking_event_ids']);
        $this->assertSame(30, $s['retention_days']);
        $this->assertArrayNotHasKey('unknown', $s);
    }

    public function testDefaultsAreOffAndDryRun(): void
    {
        $this->assertFalse(Settings::get('enabled'));
        $this->assertFalse(Settings::isLive());
    }

    public function testNextRunIsTheNextOccurrenceOfTheHour(): void
    {
        // NOW is 09:00 UTC.
        $this->assertSame(self::NOW + DAY_IN_SECONDS, Scheduler::nextRunAt(9, self::NOW));
        $this->assertSame(self::NOW + HOUR_IN_SECONDS, Scheduler::nextRunAt(10, self::NOW));
        $this->assertSame(self::NOW + 15 * HOUR_IN_SECONDS, Scheduler::nextRunAt(0, self::NOW));
    }

    public function testEnsureReschedulesWhenMissingOrAtTheWrongHour(): void
    {
        WpStub::$options[Settings::OPTION] = array('run_hour' => 9);

        Scheduler::ensure();
        $this->assertSame(9, (int) gmdate('G', WpStub::$cron[Scheduler::DAILY]));

        WpStub::$options[Settings::OPTION] = array('run_hour' => 14);
        Scheduler::ensure();
        $this->assertSame(14, (int) gmdate('G', WpStub::$cron[Scheduler::DAILY]));
    }
}
