<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;

/**
 * @psalm-immutable
 * Request-only payload for setting up new donations.
 */
readonly class DonationCreate
{
    /** @var Salesforce18Id<Campaign>  */
    public readonly Salesforce18Id $projectId;
    public readonly ?DonorName $donorName;
    public readonly ?EmailAddress $emailAddress;

    /**
     * @param string $donationAmount In full currency unit, e.g. whole pounds GBP, whole dollars USD
     * @psalm-param numeric-string $donationAmount
     * @psalm-param ?numeric-string $tipAmount
     * @throws AssertionFailedException
     */
    public function __construct(
        public string $currencyCode,
        public string $donationAmount,
        string $projectId,
        public string $psp,
        public PaymentMethodType $pspMethodType = PaymentMethodType::Card,
        public ?string $countryCode = null,
        public ?bool $optInCharityEmail = null,
        public ?bool $optInChampionEmail = null,
        public ?bool $optInTbgEmail = null,
        public ?string $pspCustomerId = null,
        public ?string $tipAmount = '0.00',
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $emailAddress = null,
        public ?bool $giftAid = null,
        public ?bool $tipGiftAid = null,
        public ?string $homeAddress = null,
        public ?string $homePostcode = null,
    ) {
        $this->emailAddress = (! is_null($emailAddress) && ! ($emailAddress === '')) ?
            EmailAddress::of($emailAddress) :
            null;

        $this->donorName = DonorName::maybeFromFirstAndLast($firstName, $lastName);

        $this->projectId = Salesforce18Id::ofCampaign($projectId);

        Assertion::betweenLength($this->donationAmount, 1, 9); // more than we need, allows up to 999k ;
        Assertion::regex(
            $this->donationAmount,
            '/^[0-9]+(\.00?)?$/',
            "Donation amount should be a whole number"
        ); // must be an integer, with optional .00 or .0 suffix

        Assertion::nullOrBetweenLength($this->tipAmount, 1, 9);
        Assertion::nullOrRegex(
            $this->tipAmount,
            '/^[0-9]+(\.\d\d?)?$/',
            "Tip amount should be number with up to two decimals"
        );
    }
}
