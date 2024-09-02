<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

/**
 * Used for a donation given as part of a regular giving mandate. May not be duplicated within one mandate, and
 * indicates the date the donation should be taken, e.g. #1 indicates the donation taken at time of mandate creation,
 * #2, will be taken one month (or possibly other regular period) later, #3 will be taken two months later etc.
 *
 * @psalm-suppress PossiblyUnusedProperty - to be used soon.
 */
readonly class DonationSequenceNumber
{
    private function __construct(
        public int $number
    ) {
        // having a mandate last for 100 years is ambitious, putting some upper limit in mostly because its better
        // than no limit, and it could catch a bug.
        Assertion::between($number, 1, 12 * 100);
    }

    public static function of(int $number): DonationSequenceNumber
    {
        return new self($number);
    }
}
