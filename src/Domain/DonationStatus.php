<?php

namespace MatchBot\Domain;

/**
 * @link https://docs.google.com/document/d/11ukX2jOxConiVT3BhzbUKzLfSybG8eie7MX0b0kG89U/edit?usp=sharing
 * @todo Consider vs. Stripe options
 *
 * @todo before merging BG2-2296 - check that the prod DB doesn't have any statuses recorded not listed below.
 */
enum DonationStatus: string
{
    public const SUCCESS_STATUSES = [self::Collected, self::Paid];

    public const NEW_STATUSES = [self::NotSet, self::Pending];

    /**
     * @link https://thebiggive.slack.com/archives/GGQRV08BZ/p1576070168066200?thread_ts=1575655432.161800&cid=GGQRV08BZ
     */
    public const REVERSED_STATUSES = [self::Refunded, self::Failed, self::Chargedback];

    case NotSet = 'NotSet';
    case Pending = 'Pending';
    case Collected = 'Collected';
    case Paid = 'Paid';
    case Refunded = 'Refunded';
    case Cancelled = 'Cancelled';
    case Failed = 'Failed';

    /**
     * Exists in database entries from 2020 only. There is now no code that can set a Chargedback status.
     * We may want to see if eventually these can be archived and moved out of the live DB, and then this case can be
     * removed.
     */
    case Chargedback = 'Chargedback';
}
