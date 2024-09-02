<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use Stripe\Mandate;

readonly class MandateService
{
    /** @psalm-suppress PossiblyUnusedMethod - will be used by DI */
    public function __construct(
        private DonationRepository $donationRepository,
    ) {
    }
    public function makeNextDonationForMandate(RegularGivingMandate $mandate): void
    {
        $mandateId = $mandate->getId();
        Assertion::notNull($mandateId);

        $lastSequenceNumber = $this->donationRepository->maxSequenceNumberForMandate($mandateId);

        throw new \Exception(
            "Implementation incomplete, found max sequence number is {$lastSequenceNumber?->number}"
        );

        // ask the mandate to calculate the approprate payment day for the next donation...
    }
}
