<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;

/**
 * This is new, about to be brought into use.
 * @psalm-suppress PossiblyUnusedProperty
 *
 * Holds details of an account set at Stripe by a donor through our system so that they can transfer funds using a
 * bank transfer and later donate those funds (or parts of those funds) to charities.
 *
 * This class originally created for the use case of mapping from stripe IDs to email addresses and names, so that
 * we can send out confirmation emails when bank transfers are recieved into the account - but may well grow to support
 * other related uses.
 */
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: DonorAccountRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STRIPE_ID', columns: ['stripeCustomerId'])]
class DonorAccount extends Model
{
    use TimestampsTrait;

    #[ORM\Embedded(class: 'EmailAddress', columnPrefix: false)]
    public readonly EmailAddress $emailAddress;

    #[ORM\Embedded(class: 'DonorName')]
    public readonly DonorName $donorName;

    #[ORM\Embedded(class: 'StripeCustomerId', columnPrefix: false)]
    public readonly StripeCustomerId $stripeCustomerId;

    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    private ?string $billingCountryCode = null;

    /**
     * From residential address, required if donor will claim Gift Aid.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $donorHomeAddressLine1 = null;

    /**
     * From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorHomePostcode = null;

    /**
     * May be a post code or equivilent from anywhere in the world,
     * so we allow up to 15 chars which has been enough for all donors in the last 12 months.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorBillingPostcode = null;

    /**
     * The payment method selected for use in off session, regular giving payments, when the donor isn't around to
     * make an individual choice for each donation. Must be a payment card, and have
     * ['setup_future_usage' => 'on_session'] selected.
     *
     * String not embeddable because ORM does not support nullable embeddables.
     */
    #[ORM\Column(type: 'string', nullable: true, length: 255)]
    private ?string $regularGivingPaymentMethod = null;

    public function __construct(EmailAddress $emailAddress, DonorName $donorName, StripeCustomerId $stripeCustomerId)
    {
        $this->createdNow();
        $this->emailAddress = $emailAddress;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->donorName = $donorName;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - to be used soon.
     */
    public function getRegularGivingPaymentMethod(): ?StripePaymentMethodId
    {
        if ($this->regularGivingPaymentMethod === null) {
            return null;
        }

        return StripePaymentMethodId::of($this->regularGivingPaymentMethod);
    }

    public function setRegularGivingPaymentMethod(StripePaymentMethodId $methodId): void
    {
        $this->regularGivingPaymentMethod = $methodId->stripePaymentMethodId;
    }

    /**
     */
    public function setBillingCountryCode(?string $billingCountryCode): void
    {
        Assertion::nullOrLength($billingCountryCode, 2);
        $this->billingCountryCode = $billingCountryCode;
    }

    public function getBillingCountryCode(): ?string
    {
        return $this->billingCountryCode;
    }

    public function getDonorHomeAddressLine1(): ?string
    {
        return $this->donorHomeAddressLine1;
    }

    public function setDonorHomeAddressLine1(?string $donorHomeAddressLine1): void
    {
        Assertion::nullOrBetweenLength($donorHomeAddressLine1, 2, 255);
        $this->donorHomeAddressLine1 = $donorHomeAddressLine1;
    }

    public function getDonorHomePostcode(): ?string
    {
        return $this->donorHomePostcode;
    }

    public function setDonorHomePostcode(?string $donorHomePostcode): void
    {
        Assertion::nullOrBetweenLength($donorHomePostcode, 3, 10);
        $this->donorHomePostcode = $donorHomePostcode;
    }

    public function getDonorBillingPostcode(): ?string
    {
        return $this->donorBillingPostcode;
    }

    public function setDonorBillingPostcode(?string $donorBillingPostcode): void
    {
        Assertion::nullOrBetweenLength($donorBillingPostcode, 1, 15);
        $this->donorBillingPostcode = $donorBillingPostcode;
    }
}
