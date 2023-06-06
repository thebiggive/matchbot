<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Domain\PaymentMethodType;

/**
 * Request-only payload for setting up new donations.
 */
class DonationCreate
{
    public ?string $countryCode = null;
    public ?string $feeCoverAmount = '0.00';
    public ?bool $giftAid = null;
    public ?bool $optInCharityEmail = null;
    public ?bool $optInChampionEmail = null;
    public ?bool $optInTbgEmail = null;
    public ?string $pspCustomerId = null;
    public ?string $tipAmount = '0.00';

    /**
     * @param string $donationAmount In full currency unit, e.g. whole pounds GBP, whole dollars USD
     * @param PaymentMethodType $paymentMethodType
     * @param string $projectId
     * @param string $psp
     */
    public function __construct(
        public string $currencyCode,
        public string $donationAmount,
        public string $projectId,
        public string $psp,
        public PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
    ) {
    }
}
