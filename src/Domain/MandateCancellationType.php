<?php

namespace MatchBot\Domain;

enum MandateCancellationType: string
{
    /**
     * Auto cancelled at creation because enrolling or matching one of the initial donations failed
     */
    case EnrollingDonationFailed = 'EnrollingDonationFailed';

    /**
     * Auto cancelled at creation because the first donation payment could not be collected
     */
    case FirstDonationUnsuccessful = 'FirstDonationUnsuccessful';

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
