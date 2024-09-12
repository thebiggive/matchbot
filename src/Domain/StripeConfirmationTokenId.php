<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

#[Embeddable]
readonly class StripeConfirmationTokenId
{
    #[Column(type: 'string')]
    public readonly string $stripeConfirmationTokenId;

    private function __construct(
        string $stripeConfirmationTokenId
    ) {
        $this->stripeConfirmationTokenId = $stripeConfirmationTokenId;
        Assertion::notEmpty($this->stripeConfirmationTokenId);
        Assertion::maxLength($this->stripeConfirmationTokenId, 255);

        // e.g. ctoken_1NnQUf2eZvKYlo2CIObdtbnb
        Assertion::regex($this->stripeConfirmationTokenId, '/^ctoken_[a-zA-Z0-9]+$/');
    }

    /**
     * @param string $stripeID - must fit the pattern for a Stripe ID.
     */
    public static function of(string $stripeID): self
    {
        return new self($stripeID);
    }
}
