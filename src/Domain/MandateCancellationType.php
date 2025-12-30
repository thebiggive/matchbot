<?php

namespace MatchBot\Domain;

enum MandateCancellationType: string
{
    /**
     * Auto cancelled at creation because enrolling or matching one of the initial donations failed
     */
    case EnrollingDonationFailed = 'EnrollingDonationFailed';

    /**
     * Canceled because the related donor account was deleted.
     */
    case DonorAccountDeleted = 'DonorAccountDeleted';

    /**
     * Auto cancelled because the automatic donation collection process repeatedly failed - likely due to an issue
     * with the donor's card or bank account.
     */
    case CollectingAutomaticDonationRepeatFailed = 'CollectingAutomaticDonationRepeatFailed';

    /**
     * Auto cancelled at creation because the first donation payment could not be collected
     */
    case FirstDonationUnsuccessful = 'FirstDonationUnsuccessful';

    /**
     * Cancelled from Pending status to make way for another regular giving mandate from the same
     * donor for the same campaign. We do not allow duplicates across pending and active states.
     */
    case ReplacedByNewMandate = 'ReplacedByNewMandate';

    /**
     * Cancelled on donor request. One of two where the cancellation date will be different to
     * creation date.
     */
    case DonorRequestedCancellation = 'DonorRequestedCancellation';

    /**
     * Cancelled by Big Give through Salesforce. One of two where the cancellation date will be different to
     *  creation date.
     */
    case BigGiveCancelled = 'BigGiveCancelled';
}
