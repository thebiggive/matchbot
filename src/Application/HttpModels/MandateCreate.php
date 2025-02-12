<?php

namespace MatchBot\Application\HttpModels;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\Country;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Money;
use MatchBot\Domain\PostCode;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeConfirmationTokenId;

/**
 * Deserializable DTO for request to create a new regular giving mandate
 */
readonly class MandateCreate
{
    public Money $amount;
    public DayOfMonth $dayOfMonth;

    /** @var Salesforce18Id<Campaign>  */
    public Salesforce18Id $campaignId;
    public ?Country $billingCountry;

    /**
     * Confirmation token must be supplied if and only if the donor doesn't already have a payment method on file
     * with us for use with regular giving. If it is supplied we will use it to reference the payment details they
     * gave to stripe on the mandate setup form. If not we will use their existing payment method instead.
     */
    public ?StripeConfirmationTokenId $stripeConfirmationTokenId;
    public ?PostCode $homePostcode;

    /**
     * @psalm-suppress PossiblyUnusedMethod - called by Symfony Serializer
     */
    public function __construct(
        // not taking any value objects as constructor params as at least with our current config or
        // any config I've been able to find the Symfony Serializer doesn't initialise their properties.
        int $amountInPence,
        string $currency,
        int $dayOfMonth,
        public bool $giftAid,
        string $campaignId,
        ?string $billingCountry,
        public ?string $billingPostcode,
        ?string $stripeConfirmationTokenId,
        public bool $tbgComms,
        public bool $charityComms,
        public ?string $homeAddress,
        ?string $homePostcode,
        bool $homeIsOutsideUK = true,
    ) {
        $this->dayOfMonth = DayOfMonth::of($dayOfMonth);
        $this->amount = Money::fromPence($amountInPence, Currency::fromIsoCode($currency));
        $this->campaignId = Salesforce18Id::ofCampaign($campaignId);
        $this->billingCountry = Country::fromAlpha2OrNull($billingCountry);

        if (is_string($homePostcode) && trim($homePostcode) !== '') {
            $this->homePostcode = Postcode::of($homePostcode, $homeIsOutsideUK);
        } else {
            $this->homePostcode = null;
        }

        if (is_string($stripeConfirmationTokenId)) {
            $this->stripeConfirmationTokenId = StripeConfirmationTokenId::of($stripeConfirmationTokenId);
        } else {
            $this->stripeConfirmationTokenId = null;
        }
    }
}
