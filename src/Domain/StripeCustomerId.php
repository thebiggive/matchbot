<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

#[Embeddable]
readonly class StripeCustomerId
{
    #[Column(type: 'string')]
    public string $stripeCustomerId;

    private function __construct(
        string $stripeCustomerId
    ) {
        $this->stripeCustomerId = $stripeCustomerId;
        Assertion::notEmpty($this->stripeCustomerId);
        Assertion::maxLength($this->stripeCustomerId, 255);
        Assertion::regex($this->stripeCustomerId, '/^cus_[a-zA-Z0-9]+$/'); // e.g. cus_df64s36cf
    }

    /**
     * @param string $stripeID - must fit the pattern for a Stripe ID.
     */
    public static function of(string $stripeID): self
    {
        return new self($stripeID);
    }
}
