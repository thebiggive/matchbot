<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

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

    /**
     * Person ID as they are known in identity service. Nullable only for now to be compatible with existing
     * data in prod.
     */
    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    protected ?UuidInterface $uuid = null;

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
    private ?string $homeAddressLine1 = null;

    /**
     * From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $homePostcode = null;

    /**
     * May be a post code or equivilent from anywhere in the world,
     * so we allow up to 15 chars which has been enough for all donors in the last 12 months.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $billingPostcode = null;

    /**
     * The payment method selected for use in off session, regular giving payments, when the donor isn't around to
     * make an individual choice for each donation. Must be a payment card, and have
     * ['setup_future_usage' => 'on_session'] selected.
     *
     * String not embeddable because ORM does not support nullable embeddables.
     */
    #[ORM\Column(type: 'string', nullable: true, length: 255)]
    private ?string $regularGivingPaymentMethod = null;

    public function __construct(
        ?PersonId $uuid,
        EmailAddress $emailAddress,
        DonorName $donorName,
        StripeCustomerId $stripeCustomerId
    ) {
        $this->createdNow();
        $this->emailAddress = $emailAddress;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->donorName = $donorName;
        $this->uuid = is_null($uuid) ? null : Uuid::fromString($uuid->id);
    }

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

    public function getHomeAddressLine1(): ?string
    {
        return $this->homeAddressLine1;
    }

    public function setHomeAddressLine1(?string $homeAddressLine1): void
    {
        Assertion::nullOrBetweenLength($homeAddressLine1, 2, 255);
        $this->homeAddressLine1 = $homeAddressLine1;
    }

    public function getHomePostcode(): ?string
    {
        return $this->homePostcode;
    }

    public function setHomePostcode(?string $homePostcode): void
    {
        Assertion::nullOrBetweenLength($homePostcode, 3, 10);
        $this->homePostcode = $homePostcode;
    }

    public function getBillingPostcode(): ?string
    {
        return $this->billingPostcode;
    }

    public function setBillingPostcode(?string $billingPostcode): void
    {
        Assertion::nullOrBetweenLength($billingPostcode, 1, 15);
        $this->billingPostcode = $billingPostcode;
    }
}
