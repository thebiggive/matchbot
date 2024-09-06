<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

#[Embeddable]
readonly class StripeConformationTokenId
{
    #[Column(type: 'string')]
    public readonly string $stripeConfirmationTokenId;

    private function __construct(
        string $stripeConfirmationTokenId
    ) {
        $this->stripeConfirmationTokenId = $stripeConfirmationTokenId;
        Assertion::notEmpty($this->stripeConfirmationTokenId);
        Assertion::maxLength($this->stripeConfirmationTokenId, 255);
        Assertion::regex($this->stripeConfirmationTokenId, '/^ctoken_[a-zA-Z0-9]+$/'); // e.g. pm_df64s36cf
    }

    /**
     * @param string $stripeID - must fit the pattern for a Stripe ID.
     */
    public static function of(string $stripeID): self
    {
        return new self($stripeID);
    }
}
