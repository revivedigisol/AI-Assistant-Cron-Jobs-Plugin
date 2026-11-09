<?php

namespace Enle\DailyCron\Contracts;

defined('ABSPATH') || exit;

/**
 * What the founder has done since their report, read from the other plugins
 * on the site (FluentCart, FluentBooking, FluentCRM).
 */
interface Signals
{
    /** Has a paid order for the Market Validation product (at any time). */
    public function hasPurchased(string $email): bool;

    /** Has booked one of the consultation events at or after $since. */
    public function hasBookedSince(string $email, int $since): bool;

    /** FluentCRM contact status ('subscribed', 'unsubscribed', …), or '' when there is no contact. */
    public function crmStatus(string $email): string;

    /** The FluentCRM contact carries the team's "replied" tag. */
    public function hasRepliedTag(string $email): bool;

    /** Sets the FluentCRM contact to unsubscribed (no-op without a contact). */
    public function unsubscribeInCrm(string $email): void;
}
