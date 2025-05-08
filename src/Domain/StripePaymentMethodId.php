<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

#[Embeddable]
readonly class StripePaymentMethodId
{
    #[Column(type: 'string')]
    public readonly string $stripePaymentMethodId;

    private function __construct(
        string $stripeCustomerId
    ) {
        $this->stripePaymentMethodId = $stripeCustomerId;
        Assertion::notEmpty($this->stripePaymentMethodId);
        Assertion::maxLength($this->stripePaymentMethodId, 255);
        Assertion::regex($this->stripePaymentMethodId, '/^pm_[a-zA-Z0-9]+$/'); // e.g. pm_df64s36cf
    }

    /**
     * @param string $stripeID - must fit the pattern for a Stripe ID.
     */
    public static function of(string $stripeID): self
    {
        return new self($stripeID);
    }

    public function equals(StripePaymentMethodId|null $that): bool
    {
        if ($that === null) {
            return false;
        }

        return $this->stripePaymentMethodId === $that->stripePaymentMethodId;
    }
}
