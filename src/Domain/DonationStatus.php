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

    /**
     * Never saved to database - this is just a placeholder used on incomplete donation objects in memory.
     * @todo consider removing this, either replace with `null` or preferably force every donation to have a real
     * status when constructed.
     */
    case NotSet = 'NotSet';

    /**
     * A Pending donation represents a non-binding statement of intent to donate. We don't know whether
     * it will turn into a real donation, but if a donation is old and still pending we can assume it will never
     * be completed.
     *
     * We temporarily reserve match funds for pending donations.
     */
    case Pending = 'Pending';

    /**
     * Set when "a charge is successful" in stripe.
     *
     * TBH I'm (bdsl) not clear on the difference in meaning between this and Paid. Both are considered succesful
     * donations.
     *
     * @see https://stripe.com/docs/api/events/types/#event_types-charge.succeeded
     */
    case Collected = 'Collected';

    /**
     * A donor has transferred the funds for their donation to Big Give.
     * @see https://stripe.com/docs/api/events/types#event_types-payout.paid
     */
    case Paid = 'Paid';

    /**
     * Set when we return the donated money - e.g. in case of a dispute or if we decided to issue a refund for
     * any other business reason.
     */
    case Refunded = 'Refunded';

    /**
     * A donor changed their mind and decided not to donate after initially declaring an intention to donate.
     *
     * Currently, this status is only set when sent explicilty from the doante-frontend, e.g. if they leave the
     * browser open for a long time without completing the donation.
     *
     * @todo In future, we might think about auto-cancelling old pending donations.
     */
    case Cancelled = 'Cancelled';

    /**
     * I guess historically this would have been set when a payment attempt failed - but we now have no code to set
     * it, so a donation with a failed payment attempt would remain Pending. As with `Chargedback` we may
     * want to remove this case defintion from the code.
     */
    case Failed = 'Failed';

    /**
     * Exists in database entries from 2020 only. There is now no code that can set this status.
     * We may want to see if eventually these can be archived and moved out of the live DB, and then this case can be
     * removed.
     */
    case Chargedback = 'Chargedback';
}
