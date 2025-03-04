<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DomainException\AccountNotReadyToDonate;
use Messages\Person;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Holds details of an account set at Stripe by a donor through our system so that they can transfer funds using a
 * bank transfer and later donate those funds (or parts of those funds) to charities; and for Regular Giving.
 *
 * This class originally created for the use case of mapping from stripe IDs to email addresses and names, so that
 * we can send out confirmation emails when bank transfers are recieved into the account.
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
    #[ORM\Column(type: 'uuid', unique: true, nullable: false)]
    protected UuidInterface $uuid;

    #[ORM\Embedded(class: 'EmailAddress', columnPrefix: false)]
    public EmailAddress $emailAddress;

    #[ORM\Embedded(class: 'DonorName')]
    public DonorName $donorName;

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
     *
     * Not embedding postcode VO directly because Doctrine doesn't allow remapping field names.
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
     * ['setup_future_usage' => 'off_session'] selected.
     *
     * String not embeddable because ORM does not support nullable embeddables.
     */
    #[ORM\Column(type: 'string', nullable: true, length: 255)]
    private ?string $regularGivingPaymentMethod = null;

    /**
     * When a home address has been supplied for GA purposes, is it outside the UK?
     *
     * In future consider adding a similar field to donations. If we use it consistently we can replace the magic
     * string `OVERSEAS`.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $homeIsOutsideUK = null;

    public function __construct(
        PersonId $uuid,
        EmailAddress $emailAddress,
        DonorName $donorName,
        StripeCustomerId $stripeCustomerId
    ) {
        $this->createdNow();
        $this->emailAddress = $emailAddress;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->donorName = $donorName;
        $this->uuid = Uuid::fromString($uuid->id);
    }

    public static function fromPersonMessage(Person $person): self
    {
        return new self(
            PersonId::of($person->id->toString()),
            EmailAddress::of($person->email_address),
            DonorName::of($person->first_name, $person->last_name),
            StripeCustomerId::of($person->stripe_customer_id),
        );
    }

    public function updateFromPersonMessage(Person $personMessage): void
    {
        $this->emailAddress = EmailAddress::of($personMessage->email_address);
        $this->donorName = DonorName::of($personMessage->first_name, $personMessage->last_name);
    }

    /**
     * UUID of this person as held in Identity service.
     **/
    public function id(): PersonId
    {
        return PersonId::of($this->uuid->toString());
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
        return $this->homeIsOutsideUK ? Donation::OVERSEAS : $this->homePostcode;
    }

    public function setHomePostcode(?PostCode $homePostcode): void
    {
        $this->homePostcode = $homePostcode?->value;
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

    /**
     * Throws if the donor does not have required fields set to allow automated donation creation.
     *
     * Fields will need to be set either during or in advance of the regular giving mandate creation process.
     */
    public function assertHasRequiredInfoForRegularGiving(): void
    {
        Assert::lazy()
            ->that($this->billingPostcode, null, 'Missing billing postcode')->notNull()
            ->that($this->billingCountryCode, null, 'Missing billing country code')->notNull()
            ->setExceptionClass(AccountNotReadyToDonate::class)
            ->verifyNow();
    }

    public function toFrontEndApiModel(): array
    {
        return [
            'id' => $this->uuid->toString(),
            'fullName' => $this->donorName->fullName(),
            'stripeCustomerId' => $this->stripeCustomerId->stripeCustomerId,
            'regularGivingPaymentMethod' => $this->regularGivingPaymentMethod,
            'billingPostCode' => $this->billingPostcode,
            'billingCountryCode' => $this->billingCountryCode,
        ];
    }

    public function setBillingCountry(Country $billingCountry): void
    {
        $this->billingCountryCode = $billingCountry->alpha2->value;
    }

    public function getBillingCountry(): ?Country
    {
        return Country::fromAlpha2OrNull($this->billingCountryCode);
    }

    public function toSfApiModel(): array
    {
        return [
            'firstName' => $this->donorName->first,
            'lastName' => $this->donorName->last,
            'emailAddress' => $this->emailAddress->email,
            'billingPostalAddress' => $this->billingPostcode,
            'countryCode' => $this->billingCountryCode,
            'pspCustomerId' => $this->stripeCustomerId->stripeCustomerId,
            'identityUUID' => $this->uuid->toString(),
        ];
    }

    public function hasHomeAddress(): bool
    {
        return is_string($this->homeAddressLine1) && trim($this->homeAddressLine1) !== '';
    }

    public function setHomeIsOutsideUK(bool $homeIsOutsideUK): void
    {
        $this->homeIsOutsideUK = $homeIsOutsideUK;
    }

    public function isHomeOutsideUK(): ?bool
    {
        return $this->homeIsOutsideUK;
    }
}
