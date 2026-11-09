<?php

namespace Enle\DailyCron;

use Enle\DailyCron\Admin\AdminPage;
use Enle\DailyCron\Alerts\Alerter;
use Enle\DailyCron\Cli\Command;
use Enle\DailyCron\Cron\Lock;
use Enle\DailyCron\Cron\Runner;
use Enle\DailyCron\Cron\Scheduler;
use Enle\DailyCron\Email\ClaudeClient;
use Enle\DailyCron\Email\Unsubscribe;
use Enle\DailyCron\Email\WpMailer;
use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Sequence\Enrolment;
use Enle\DailyCron\Sequence\StopRules;
use Enle\DailyCron\Storage\FluentSignals;
use Enle\DailyCron\Storage\Schema;
use Enle\DailyCron\Storage\WpdbLeadStore;

defined('ABSPATH') || exit;

/**
 * Wires the plugin into WordPress. Services are built lazily, so a normal
 * page view costs a couple of hook registrations and nothing else.
 */
final class Plugin
{
    /** @var array<string,object> */
    private static array $services = array();

    public static function boot(): void
    {
        // Enrolment: ai-assistant fires this when a report is delivered.
        add_action(Enrolment::ACTION, static function ($payload): void {
            self::schemaReady();
            $result = self::enrolment()->handle($payload, time());

            if ($result['result'] === 'ignored_invalid') {
                error_log('Enle Daily Cron: report-ready payload ignored (no valid email or report).');
            }
        }, 10, 1);

        add_action(Scheduler::DAILY, static fn () => self::runner()->run());
        add_action(Scheduler::CONTINUE, static fn () => self::runner()->run());
        add_action(Scheduler::SWEEP, static fn () => self::runner()->sweepStops());

        // A purchase or booking stops the sequence within minutes. Only a
        // check is scheduled here, so checkout and booking stay fast and
        // don't depend on those plugins' hook arguments.
        add_action('fluent_cart/order_paid', array(Scheduler::class, 'sweepSoon'));
        add_action('fluent_booking/after_booking_scheduled', array(Scheduler::class, 'sweepSoon'));

        add_action('init', array(Schema::class, 'maybeUpgrade'), 5);
        add_action('init', array(Scheduler::class, 'ensure'), 20);
        add_action('init', static fn () => self::unsubscribe()->maybeHandle(), 1);

        if (is_admin()) {
            AdminPage::register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('enle-dc', Command::class);
        }
    }

    public static function activate(bool $network_wide = false): void
    {
        // Agreed scope: enle.org (the main site) only. Network activation
        // would run the sequence on every subsite.
        if (is_multisite() && $network_wide) {
            wp_die(
                esc_html__('Enle Daily Cron runs on one site only. Activate it on enle.org from that site\'s Plugins screen, not network-wide.', 'enle-daily-cron'),
                esc_html__('Plugin not activated', 'enle-daily-cron'),
                array('back_link' => true)
            );
        }

        Schema::install();
        Scheduler::schedule();
    }

    public static function deactivate(): void
    {
        Scheduler::clear();
        Lock::clear();
    }

    public static function runner(): Runner
    {
        self::schemaReady();

        return self::$services['runner'] ??= new Runner(
            self::store(),
            new StopRules(self::signals()),
            self::writer(),
            new WpMailer(),
            new Alerter(new WpMailer()),
            new Lock(),
        );
    }

    public static function writer(): Writer
    {
        return self::$services['writer'] ??= new Writer(
            new ClaudeClient(ClaudeClient::apiKey(), (string) Settings::get('model')),
            self::links()
        );
    }

    /** @return array{programme_url:string,booking_url:string,programme_price:string} */
    public static function links(): array
    {
        return array(
            'programme_url'   => (string) Settings::get('programme_url'),
            'booking_url'     => (string) Settings::get('booking_url'),
            'programme_price' => (string) Settings::get('programme_price'),
        );
    }

    public static function store(): WpdbLeadStore
    {
        return self::$services['store'] ??= new WpdbLeadStore();
    }

    public static function signals(): FluentSignals
    {
        return self::$services['signals'] ??= new FluentSignals();
    }

    public static function enrolment(): Enrolment
    {
        return self::$services['enrolment'] ??= new Enrolment(self::store());
    }

    public static function unsubscribe(): Unsubscribe
    {
        return self::$services['unsubscribe'] ??= new Unsubscribe(self::store(), self::signals());
    }

    /** Settings changed: drop services built from the old values. */
    public static function reset(): void
    {
        self::$services = array();
    }

    private static function schemaReady(): void
    {
        Schema::maybeUpgrade();
    }
}
