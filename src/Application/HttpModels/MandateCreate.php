<?php

namespace MatchBot\Application\HttpModels;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;

/**
 * Deserializable DTO for request to create a new regular giving mandate
 */
readonly class MandateCreate
{
    public Money $amount;
    public DayOfMonth $dayOfMonth;

    /** @var Salesforce18Id<Campaign>  */
    public Salesforce18Id $campaignId;

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
        string $campaignId
    ) {
        $this->dayOfMonth = DayOfMonth::of($dayOfMonth);
        $this->amount = Money::fromPence($amountInPence, Currency::fromIsoCode($currency));
        $this->campaignId = Salesforce18Id::ofCampaign($campaignId);
    }
}
