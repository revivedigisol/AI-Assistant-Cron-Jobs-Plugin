<?php

namespace Enle\DailyCron\Sequence;

use Enle\DailyCron\Contracts\Signals;
use Enle\DailyCron\Domain\Lead;

defined('ABSPATH') || exit;

/**
 * The sequence rules that end it early ("Let customer behaviour control the
 * sequence"). Agreed 2026-11-09: a purchase or a booking stops the sequence
 * for good; a reply (the team adds the "Replied" tag) stops it too.
 */
final class StopRules
{
    public const PURCHASED    = 'purchased';
    public const BOOKED       = 'booked';
    public const REPLIED      = 'replied';
    public const UNSUBSCRIBED = 'unsubscribed';

    /** FluentCRM statuses that mean "don't email this person". */
    private const CRM_BLOCKED = array('unsubscribed', 'bounced', 'complained', 'spammed');

    public function __construct(private Signals $signals)
    {
    }

    /** The reason to stop, or '' to carry on. Cheapest and most important checks first. */
    public function reasonToStop(Lead $lead): string
    {
        if (in_array($this->signals->crmStatus($lead->email), self::CRM_BLOCKED, true)) {
            return self::UNSUBSCRIBED;
        }

        if ($this->signals->hasPurchased($lead->email)) {
            return self::PURCHASED;
        }

        if ($this->signals->hasBookedSince($lead->email, $lead->enrolled_at)) {
            return self::BOOKED;
        }

        if ($this->signals->hasRepliedTag($lead->email)) {
            return self::REPLIED;
        }

        return '';
    }

    /** Stops that need a person to act (sent straight to the team). */
    public static function needsTeam(string $reason): bool
    {
        return $reason === self::PURCHASED || $reason === self::BOOKED;
    }

    public static function describe(string $reason): string
    {
        return match ($reason) {
            self::PURCHASED    => 'bought Market Validation',
            self::BOOKED       => 'booked a consultation',
            self::REPLIED      => 'replied (tagged by the team)',
            self::UNSUBSCRIBED => 'unsubscribed',
            'manual'           => 'stopped by an admin',
            default            => $reason,
        };
    }
}
