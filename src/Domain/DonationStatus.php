<?php

namespace MatchBot\Domain;

enum DonationStatus: string
{
    public const SUCCESS_STATUSES = [self::Collected, self::Paid];

    /**
     * @return bool Whether this donation is *currently* in a state that we consider to be successful.
     *              Note that this is not guaranteed to be permanent: donations can be refunded or charged back after
     *              being in a state where this method is `true`.
     */
    public function isSuccessful(): bool
    {
        return in_array($this, self::SUCCESS_STATUSES, true);
    }

    /**
     * @link https://thebiggive.slack.com/archives/GGQRV08BZ/p1576070168066200?thread_ts=1575655432.161800&cid=GGQRV08BZ
     */
    public function isReversed(): bool
    {
        return match ($this) {
            self::Refunded, self::Failed, self::Chargedback => true,
            default => false,
        };
    }

    /**
     * A Pending donation represents a non-binding statement of intent to donate. We don't know whether
     * it will turn into a real donation, but if a donation is old and still pending we can assume it will never
     * be completed.
     *
     * We temporarily reserve match funds for pending donations.
     */
    case Pending = 'Pending';

    /**
     * Donor has given us advance permission to collect a donation but only on or after a specified time. Intended to be
     * used for the 2nd and 3rd donations taken as part of a regular giving plan, which will be matched but not
     * collected during the donor's online session.
     *
     * @see Donation::$preAuthorizationDate
     */
    case PreAuthorized = 'PreAuthorized';

    /**
     * Donation has been paid in to Big Give's Stripe account, but not yet transferred to the charity.
     *
     * Generally this should just be temporary status, but there are a few old donations marked 'Collected' in the DB
     * for historical reasons.
     *
     * Set when receive the charge.succeded event from Stripe.
     * @see https://stripe.com/docs/api/events/types/#event_types-charge.succeeded
     */
    case Collected = 'Collected';

    /**
     * Donation has been paid out to the charity.
     *
     * Set when we receive the payout.paid event from Stripe.
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
     * Currently, this status is only set when sent explicitly from the donate-frontend, e.g. if they leave the
     * browser open for a long time without completing the donation.
     *
     * @todo In future, we might think about auto-cancelling old pending donations - or alternately merging this status
     *       with Pending if we don't need a distinction.
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
     * removed
     */
    case Chargedback = 'Chargedback';
}
