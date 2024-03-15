<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\LazyAssertionException;
use Messages;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

use function bccomp;
use function sprintf;

#[ORM\Table]
#[ORM\Index(name: 'campaign_and_status', columns: ['campaign_id', 'donationStatus'])]
#[ORM\Index(name: 'date_and_status', columns: ['createdAt', 'donationStatus'])]
#[ORM\Index(name: 'salesforcePushStatus', columns: ['salesforcePushStatus'])]
#[ORM\Entity(repositoryClass: DonationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Donation extends SalesforceWriteProxy
{
    /**
     * @see Donation::$currencyCode
     */
    public const MAXIMUM_CARD_DONATION = 25_000;

    public const MAXIMUM_CUSTOMER_BALANCE_DONATION = 200_000;
    public const MINUMUM_AMOUNT = 1;

    private array $possiblePSPs = ['stripe'];

    /**
     * The donation ID for PSPs and public APIs. Not the same as the internal auto-increment $id used
     * by Doctrine internally for fast joins.
     *
     * @var UuidInterface|null
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    protected ?UuidInterface $uuid = null;

    /**
     * @var Campaign
     */
    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    protected Campaign $campaign;

    /**
     * @var string  Which Payment Service Provider (PSP) is expected to (or did) process the donation.
     */
    #[ORM\Column(type: 'string', length: 20)]
    protected string $psp;

    /**
     * @var ?DateTimeImmutable  When the donation first moved to status Collected, i.e. the donor finished paying.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $collectedAt = null;

    /**
     * @var string|null PSP's transaction ID assigned on their processing.
     *
     * In the case of stripe (which is the only thing we support at present, this is the payment intent ID)
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $transactionId = null;

    /**
     * @var string|null PSP's charge ID assigned on their processing.
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $chargeId = null;

    /**
     * @var string|null PSP's transfer ID assigned on a successful charge. For Stripe this
     *                  ID relates to the Platform Account (i.e. the Big Give's) rather than
     *                  the Connected Account for the charity receiving the transferred
     *                  donation balance.
     */
    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    protected ?string $transferId = null;

    /**
     * @var string  ISO 4217 code for the currency in which all monetary values are denominated, e.g. 'GBP'.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected readonly string $currencyCode;

    /**
     * Core donation amount in major currency units (i.e. Pounds) excluding any tip.
     *
     * @psalm-var numeric-string Always use bcmath methods as in repository helpers to avoid doing float maths
     *                           with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected readonly string $amount;

    /**
     * Fee the charity takes on, in £. Excludes any tax if applicable.
     *
     * For Stripe (EU / UK): 1.5% of $amount + 0.20p
     * For Stripe (Non EU / Amex): 3.2% of $amount + 0.20p
     *
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $charityFee = '0.00';

    /**
     * Value Added Tax amount on `$charityFee`, in £. In addition to base amount
     * in $charityFee.
     *
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $charityFeeVat = '0.00';

    /**
     * Fee charged to TBG by the PSP, in £. Added just for Stripe transactions from March '21.
     * Original fee varies by card brand and country and is based on the full amount paid by the
     * donor: `$amount + $tipAmount`.
     *
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $originalPspFee = '0.00';

    #[ORM\Column(type: 'string', enumType: DonationStatus::class)]
    protected DonationStatus $donationStatus = DonationStatus::Pending;

    /**
     * @var bool    Whether the donor opted to receive email from the charity running the campaign
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $charityComms = null;

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $giftAid = null;

    /**
     * @var bool    Whether the donor opted to receive email from the Big Give
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tbgComms = null;

    /**
     * @var bool    Whether the donor opted to receive email from the champion funding the campaign
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $championComms = null;

    /**
     * @var string|null  *Billing* country code.
     */
    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    protected ?string $donorCountryCode = null;

    /**
     * Ideally we would type this as ?EmailAddress instead of ?string but that will require changing
     * the column name to match the property inside the VO. Might be easy and worth doing.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorEmailAddress = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorFirstName = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorLastName = null;

    /**
     * @var string|null Assumed to be billing address going forward.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorPostalAddress = null;

    /**
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorHomeAddressLine1 = null;

    /**
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $donorHomePostcode = null;

    /**
     * @var string  Amount donor chose to add to cover a fee, including any tax.
     *              Precision numeric string.
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $feeCoverAmount = '0.00';

    /**
     * @var string  Amount donor chose to tip. Precision numeric string.
     *              Set during donation setup and can also be modified later if the donor changes only this.
     * @see Donation::$currencyCode
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    protected string $tipAmount = '0.00';

    /**
     * @var bool    Whether Gift Aid was claimed on the 'tip' donation to the Big Give.
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tipGiftAid = null;

    /**
     * @var bool    Whether any Gift Aid claim should be made by the Big Give as an agent/nominee
     *              *if* `$giftAid is true too. This field is set independently to allow for claim
     *              status amendments so we must not assume a donation can actualy be claimed just
     *              because it's true.
     * @see Donation::$giftAid
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tbgShouldProcessGiftAid = null;

    /**
     * @var ?DateTime   When a queued message that should lead to a Gift Aid claim was sent.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $tbgGiftAidRequestQueuedAt = null;

    /**
     * @var ?DateTime   When a claim submission attempt was detected to have an error returned.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $tbgGiftAidRequestFailedAt = null;

    /**
     * @var ?DateTime   When a claim was detected accepted via an async poll.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $tbgGiftAidRequestConfirmedCompleteAt = null;

    /**
     * @var ?string Provided by HMRC upon initial claim submission acknowledgement.
     *              Doesn't imply success.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $tbgGiftAidRequestCorrelationId = null;

    /**
     * @var ?string Verbatim final errors or messages from HMRC received immediately or
     *              (most likely based on real world observation) via an async poll.
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    protected ?string $tbgGiftAidResponseDetail = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $pspCustomerId = null;

    #[ORM\Column(type: 'string', enumType: PaymentMethodType::class, nullable: true)]
    protected ?PaymentMethodType $paymentMethodType = PaymentMethodType::Card;

    /**
     * @var Collection<int,FundingWithdrawal>
     */
    #[ORM\OneToMany(targetEntity: FundingWithdrawal::class, mappedBy: 'donation', fetch: 'EAGER')]
    protected $fundingWithdrawals;

    /**
     * Date at which we refunded this to the donor. Ideally will be null. Should be not null only iff status is
     * DonationStatus::Refunded
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    /**
     * @param string $amount
     * @deprecated but retained for now as used in old test classes. Not recommend for continued use - either use
     * fromApiModel or create a new named constructor that takes required data for your use case.
     */
    public static function emptyTestDonation(
        string $amount,
        PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
        string $currencyCode = 'GBP'
    ): self {
        $donation = new self($amount, $currencyCode, $paymentMethodType);
        $donation->psp = 'stripe';

        return $donation;
    }

    /**
     * @psalm-param numeric-string $amount
     */
    private function __construct(string $amount, string $currencyCode, PaymentMethodType $paymentMethodType)
    {
        $this->fundingWithdrawals = new ArrayCollection();
        $this->currencyCode = $currencyCode;
        $maximumAmount = self::maximumAmount($paymentMethodType);

        if (
            bccomp($amount, (string)self::MINUMUM_AMOUNT, 2) === -1 ||
            bccomp($amount, (string)$maximumAmount, 2) === 1
        ) {
            throw new \UnexpectedValueException(sprintf(
                'Amount must be %d-%d %s',
                self::MINUMUM_AMOUNT,
                $maximumAmount,
                $this->currencyCode,
            ));
        }

        $this->amount = $amount;
        $this->paymentMethodType = $paymentMethodType;
    }

    /**
     * @throws \Assert\AssertionFailedException
     * @throws \UnexpectedValueException
     */
    public static function fromApiModel(DonationCreate $donationData, Campaign $campaign): Donation
    {
        $psp = $donationData->psp;
        Assertion::eq($psp, 'stripe');

        $donation = new self(
            $donationData->donationAmount,
            $donationData->currencyCode,
            $donationData->pspMethodType,
        );

        $donation->setPsp($psp);
        $donation->setUuid(Uuid::uuid4());
        $donation->setCampaign($campaign); // Charity & match expectation determined implicitly from this

        // Gift Aid is always set to false at donation creation time. It can only be set to true if the donor supplies
        // their address later.
        $donation->setGiftAid(false);
        // `DonationCreate` doesn't support a distinct property yet & we only ask once about GA.
        $donation->setTipGiftAid(false);

        $donation->setTbgShouldProcessGiftAid($campaign->getCharity()->isTbgClaimingGiftAid());

        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setChampionComms($donationData->optInChampionEmail);
        $donation->setPspCustomerId($donationData->pspCustomerId);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setDonorName($donationData->donorName);
        $donation->setDonorEmailAddress($donationData->emailAddress);

        if (!empty($donationData->countryCode)) {
            $donation->setDonorCountryCode($donationData->countryCode);
        }

        if (isset($donationData->feeCoverAmount)) {
            $donation->setFeeCoverAmount($donationData->feeCoverAmount);
        }

        if (isset($donationData->tipAmount)) {
            $donation->setTipAmount($donationData->tipAmount);
        }

        return $donation;
    }

    private static function maximumAmount(PaymentMethodType $paymentMethodType): int
    {
        return match ($paymentMethodType) {
            PaymentMethodType::CustomerBalance => self::MAXIMUM_CUSTOMER_BALANCE_DONATION,
            PaymentMethodType::Card => self::MAXIMUM_CARD_DONATION,
        };
    }

    public function __toString(): string
    {
        // if we're in __toString then probably something has already gone wrong, and we don't want to allow
        // any more crashes during the logging process.
        try {
            $charityName = $this->getCampaign()->getCharity()->getName();
        } catch (\Throwable $t) {
            // perhaps the charity was never pulled from Salesforce into our database, in which case we might
            // have a TypeError trying to get a string name from it.
            $charityName = "[pending charity threw " . get_class($t) . "]";
        }
        return "Donation {$this->getUuid()} to $charityName";
    }

    /*
     * In contrast to __toString, this is used when creating a payment intent. If we can't find the charity name
     * then we should let the process of making the intent and registering the donation crash.
     */
    public function getDescription(): string
    {
        $charityName = $this->getCampaign()->getCharity()->getName();
        return "Donation {$this->getUuid()} to $charityName";
    }

    /**
     * @param PreUpdateEventArgs $args
     * @throws \LogicException if amount is changed
     */
    #[ORM\PreUpdate]
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('amount')) {
            return;
        }

        if ($args->getOldValue('amount') !== $args->getNewValue('amount')) {
            throw new \LogicException('Amount may not be changed after a donation is created');
        }
    }

    public function replaceNullPaymentMethodTypeWithCard(): void
    {
        if ($this->paymentMethodType !== null) {
            throw new \Exception('Should only be called when payment method type is null');
        }
        $this->paymentMethodType = PaymentMethodType::Card;
    }

    /**
     * @return array A json encode-ready array representation of the donation, for sending to Salesforce.
     */
    public function toHookModel(): array
    {
        $data = $this->toApiModel();

        // MAT-234 - remove dubious patterns from email for now so records can save in SF.
        if ($data['emailAddress'] !== null && str_contains($data['emailAddress'], ';;')) {
            $data['emailAddress'] = str_replace(';;', '', $data['emailAddress']);
        }

        $data['updatedTime'] = $this->getUpdatedDate()->format(DateTimeInterface::ATOM);
        $data['amountMatchedByChampionFunds'] = (float) $this->getConfirmedChampionWithdrawalTotal();
        $data['amountMatchedByPledges'] = (float) $this->getConfirmedPledgeWithdrawalTotal();
        $data['originalPspFee'] = (float) $this->getOriginalPspFee();
        $data['refundedTime'] = $this->refundedAt?->format(DateTimeInterface::ATOM);
        $data['tbgGiftAidRequestConfirmedCompleteAt'] =
            $this->tbgGiftAidRequestConfirmedCompleteAt?->format(DateTimeInterface::ATOM);
        unset(
            $data['charityName'],
            $data['donationId'],
            $data['matchReservedAmount'],
            $data['matchedAmount'],
            $data['cardBrand'],
            $data['cardCountry'],
        );

        return $data;
    }

    public function toApiModel(): array
    {
        $data = [
            'billingPostalAddress' => $this->getDonorBillingAddress(),
            'charityFee' => (float) $this->getCharityFee(),
            'charityFeeVat' => (float) $this->getCharityFeeVat(),
            'charityId' => $this->getCampaign()->getCharity()->getSalesforceId(),
            'charityName' => $this->getCampaign()->getCharity()->getName(),
            'countryCode' => $this->getDonorCountryCode(),
            'collectedTime' => $this->getCollectedAt()?->format(DateTimeInterface::ATOM),
            'createdTime' => $this->getCreatedDate()->format(DateTimeInterface::ATOM),
            'currencyCode' => $this->getCurrencyCode(),
            'donationAmount' => (float) $this->getAmount(),
            'donationId' => $this->getUuid(),
            'donationMatched' => $this->getCampaign()->isMatched(),
            'emailAddress' => $this->getDonorEmailAddress()?->email,
            'feeCoverAmount' => (float) $this->getFeeCoverAmount(),
            'firstName' => $this->getDonorFirstName(true),
            'giftAid' => $this->hasGiftAid(),
            'homeAddress' => $this->getDonorHomeAddressLine1(),
            'homePostcode' => $this->getDonorHomePostcode(),
            'lastName' => $this->getDonorLastName(true),
            'matchedAmount' => $this->getDonationStatus()->isSuccessful()
                ? (float) $this->getFundingWithdrawalTotal()
                : 0,
            'matchReservedAmount' => 0,
            'optInCharityEmail' => $this->getCharityComms(),
            'optInChampionEmail' => $this->getChampionComms(),
            'optInTbgEmail' => $this->getTbgComms(),
            'projectId' => $this->getCampaign()->getSalesforceId(),
            'psp' => $this->getPsp(),
            'pspCustomerId' => $this->getPspCustomerId(),
            'pspMethodType' => $this->getPaymentMethodType()?->value,
            'status' => $this->getDonationStatus(),
            'tipAmount' => (float) $this->getTipAmount(),
            'tipGiftAid' => $this->hasTipGiftAid(),
            'transactionId' => $this->getTransactionId(),
        ];

        if ($this->getDonationStatus() === DonationStatus::Pending) {
            $data['matchReservedAmount'] = (float) $this->getFundingWithdrawalTotal();
        }

        return $data;
    }

    public function getDonationStatus(): DonationStatus
    {
        return $this->donationStatus;
    }

    public function setDonationStatus(DonationStatus $donationStatus): void
    {
        if ($donationStatus === DonationStatus::Refunded) {
            throw new \Exception('Donation::recordRefundAt must be used to set refunded status');
        }

        if ($donationStatus === DonationStatus::Cancelled) {
            throw new \Exception('Donation::cancelled must be used to cancel');
        }


        if ($donationStatus === DonationStatus::Collected) {
            throw new \Exception('Donation::collectFromStripe must be used to collect');
        }

        $this->donationStatus = $donationStatus;
    }

    public function getCollectedAt(): ?DateTimeImmutable
    {
        return $this->collectedAt;
    }

    /**
     * @return Campaign
     */
    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    /**
     * @param Campaign $campaign
     */
    public function setCampaign(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    public function getDonorEmailAddress(): ?EmailAddress
    {
        return ((bool) $this->donorEmailAddress) ? EmailAddress::of($this->donorEmailAddress) : null;
    }

    public function setDonorEmailAddress(?EmailAddress $donorEmailAddress): void
    {
        $this->donorEmailAddress = $donorEmailAddress?->email;
    }

    public function getCharityComms(): ?bool
    {
        return $this->charityComms;
    }

    public function setCharityComms(?bool $charityComms): void
    {
        $this->charityComms = $charityComms;
    }

    public function getChampionComms(): ?bool
    {
        return $this->championComms;
    }

    public function setChampionComms(?bool $championComms): void
    {
        $this->championComms = $championComms;
    }

    public function getDonorFirstName(bool $salesforceSafe = false): ?string
    {
        $firstName = $this->donorFirstName;

        if ($salesforceSafe) {
            $firstName = $this->makeSalesforceSafe($firstName, false);
        }

        return $firstName;
    }

    public function setDonorName(?DonorName $donorName): void
    {
        $this->donorFirstName = $donorName?->first;
        $this->donorLastName = $donorName?->last;
    }

    public function getDonorLastName(bool $salesforceSafe = false): ?string
    {
        $lastName = $this->donorLastName;

        if ($salesforceSafe) {
            $lastName = $this->makeSalesforceSafe($lastName, true);
        }

        return $lastName;
    }

    public function getDonorBillingAddress(): ?string
    {
        return $this->donorPostalAddress;
    }

    public function setDonorBillingAddress(?string $donorPostalAddress): void
    {
        $this->donorPostalAddress = $donorPostalAddress;
    }

    public function hasGiftAid(): ?bool
    {
        return $this->giftAid;
    }

    private function setGiftAid(?bool $giftAid): void
    {
        $this->giftAid = $giftAid;

        // Default tip Gift Aid to main Gift Aid value. If it is set explicitly
        // first this will be skipped. If set explicitly after, the later call
        // will persist.
        if ($this->tipGiftAid === null) {
            $this->tipGiftAid = $giftAid;
        }
    }

    public function getTbgComms(): ?bool
    {
        return $this->tbgComms;
    }

    public function setTbgComms(?bool $tbgComms): void
    {
        $this->tbgComms = $tbgComms;
    }

    /**
     * Get core donation amount excluding any tip or fee cover.
     *
     * @return string   In full pounds GBP.
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @return string   In full pounds GBP. Net fee if VAT is added.
     */
    public function getCharityFee(): string
    {
        return $this->charityFee;
    }

    #[Pure] public function getCharityFeeGross(): string
    {
        return bcadd($this->getCharityFee(), $this->getCharityFeeVat(), 2);
    }

    public function setCharityFee(string $charityFee): void
    {
        $this->charityFee = $charityFee;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    /**
     * @return string|null
     */
    public function getTransferId(): ?string
    {
        return $this->transferId;
    }

    public function getDonorCountryCode(): ?string
    {
        return $this->donorCountryCode;
    }

    /**
     * @psalm-return numeric-string   Total amount in withdrawals - not necessarily finalised.
     */
    public function getFundingWithdrawalTotal(): string
    {
        $withdrawalTotal = '0.0';
        foreach ($this->fundingWithdrawals as $fundingWithdrawal) {
            $withdrawalTotal = bcadd($withdrawalTotal, $fundingWithdrawal->getAmount(), 2);
        }

        return $withdrawalTotal;
    }

    /**
     * @return string Total amount *finalised*, matched by `Fund`s of type "championFund"
     */
    private function getConfirmedChampionWithdrawalTotal(): string
    {
        if (!$this->getDonationStatus()->isSuccessful()) {
            return '0.0';
        }

        $withdrawalTotal = '0.0';
        foreach ($this->fundingWithdrawals as $fundingWithdrawal) {
            // Rely on Doctrine `SINGLE_TABLE` inheritance structure to derive the type from the concrete class.
            if ($fundingWithdrawal->getCampaignFunding()->getFund() instanceof ChampionFund) {
                $withdrawalTotal = bcadd($withdrawalTotal, $fundingWithdrawal->getAmount(), 2);
            }
        }

        return $withdrawalTotal;
    }

    /**
     * @return string Total amount *finalised*, matched by `Fund`s of type "pledge"
     */
    private function getConfirmedPledgeWithdrawalTotal(): string
    {
        if (!$this->getDonationStatus()->isSuccessful()) {
            return '0.0';
        }

        $withdrawalTotal = '0.0';
        foreach ($this->fundingWithdrawals as $fundingWithdrawal) {
            // Rely on Doctrine `SINGLE_TABLE` inheritance structure to derive the type from the concrete class.
            if ($fundingWithdrawal->getCampaignFunding()->getFund() instanceof Pledge) {
                $withdrawalTotal = bcadd($withdrawalTotal, $fundingWithdrawal->getAmount(), 2);
            }
        }

        return $withdrawalTotal;
    }

    /**
     * We may call this safely *only* after a donation has a PSP's transaction ID.
     * Stripe assigns the ID before we return a usable donation object to the Donate client,
     * so this should be true in most of the app.
     *
     * @throws \LogicException if the transaction ID is not set
     */
    public function getTransactionId(): string
    {
        if (!$this->transactionId) {
            throw new \LogicException('Transaction ID not set');
        }

        return $this->transactionId;
    }

    public function getChargeId(): ?string
    {
        return $this->chargeId;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid->toString();
    }

    /**
     * @param UuidInterface $uuid
     */
    public function setUuid(UuidInterface $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function addFundingWithdrawal(FundingWithdrawal $fundingWithdrawal): void
    {
        $this->fundingWithdrawals->add($fundingWithdrawal);
    }

    /**
     * @return Collection<int, FundingWithdrawal>
     */
    public function getFundingWithdrawals(): Collection
    {
        return $this->fundingWithdrawals;
    }

    /**
     * @param string $donorCountryCode
     */
    public function setDonorCountryCode(string $donorCountryCode): void
    {
        $this->donorCountryCode = $donorCountryCode;
    }

    /**
     * @return string   Payment Service Provider short identifier, e.g. 'stripe'.
     */
    public function getPsp(): string
    {
        return $this->psp;
    }

    /**
     * @param string $psp   Payment Service Provider short identifier, e.g. 'stripe'.
     */
    private function setPsp(string $psp): void
    {
        if (!in_array($psp, $this->possiblePSPs, true)) {
            throw new \UnexpectedValueException("Unexpected PSP '$psp'");
        }

        $this->psp = $psp;
    }

    /**
     * @return string
     */
    public function getFeeCoverAmount(): string
    {
        return $this->feeCoverAmount;
    }

    /**
     * @param string $feeCoverAmount
     * @throws \UnexpectedValueException if amount is non-zero
     */
    public function setFeeCoverAmount(string $feeCoverAmount): void
    {
        if ($feeCoverAmount !== '0' && $feeCoverAmount !== '0.00') {
            // We do not currently offer fee cover. If/when we do offer it we will need to add code here to allow
            // appropriate non-zero cover - I expect it will need to exactly match the fee being covered.
            throw new \UnexpectedValueException('Fee cover amount must be "0"');
        }

        $this->feeCoverAmount = $feeCoverAmount;
    }

    /**
     * @return string
     */
    public function getTipAmount(): string
    {
        return $this->tipAmount;
    }

    public function setTipAmount(string $tipAmount): void
    {
        /** @psalm-var numeric-string $tipAmount */
        if (
            $this->paymentMethodType === PaymentMethodType::CustomerBalance &&
            bccomp($tipAmount, '0', 2) !== 0
        ) {
            // We would have accepted a tip at the time the customer balance was created, so we don't take a second
            // tip as part of the donation.
            throw new \UnexpectedValueException('A Customer Balance Donation may not include a tip');
        }

        $max = self::MAXIMUM_CARD_DONATION;

        if (bccomp($tipAmount, (string)(self::MAXIMUM_CARD_DONATION), 2) === 1) {
            throw new \UnexpectedValueException(sprintf(
                'Tip amount must not exceed %d %s',
                $max,
                $this->currencyCode,
            ));
        }

        if (bccomp($tipAmount, '0', 2) === -1) {
            throw new \UnexpectedValueException('Tip amount must not be negative');
        }

        $this->tipAmount = $tipAmount;
    }

    public function hasTipGiftAid(): ?bool
    {
        return $this->tipGiftAid;
    }

    public function setTipGiftAid(?bool $tipGiftAid): void
    {
        $this->tipGiftAid = $tipGiftAid;
    }

    private function getDonorHomeAddressLine1(): ?string
    {
        return $this->donorHomeAddressLine1;
    }

    private function setDonorHomeAddressLine1(?string $donorHomeAddressLine1): void
    {
        $this->donorHomeAddressLine1 = $donorHomeAddressLine1;
    }

    private function getDonorHomePostcode(): ?string
    {
        return $this->donorHomePostcode;
    }

    public function setDonorHomePostcode(?string $donorHomePostcode): void
    {
        $this->donorHomePostcode = $donorHomePostcode;
    }

    /**
     * @return string
     */
    public function getCharityFeeVat(): string
    {
        return $this->charityFeeVat;
    }

    /**
     * @param string $charityFeeVat
     */
    public function setCharityFeeVat(string $charityFeeVat): void
    {
        $this->charityFeeVat = $charityFeeVat;
    }

    /**
     * @return bool Whether the donation has a hook-updated status and should therefore be updated in Salesforce after
     *              creation, if successful SF create doesn't happen before MatchBot processes the hook.
     */
    public function hasPostCreateUpdates(): bool
    {
        return $this->getDonationStatus() !== DonationStatus::Pending;
    }

    /**
     * @return int      The amount of the total donation, in cents/pence/..., which is to be excluded
     *                  from payout to the charity. This is the sum of
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     */
    public function getAmountToDeductFractional(): int
    {
        $amountToDeduct = bcadd($this->getTipAmount(), $this->getCharityFeeGross(), 2);

        return (int) bcmul('100', $amountToDeduct, 2);
    }

    /**
     * @return int      The amount of the core donation, in pence/cents/..., which is to be paid out
     *                  to the charity. This is the amount paid by the donor minus
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     *
     *                  This is just used in unit tests to validate we haven't broken anything now.
     *                  Note that because `getAmountToDeductFractional()` takes off the tip amount and
     *                  `getAmount()` relates to core amount, we must re-add the tip here to get a
     *                  correct answer.
     */
    public function getAmountForCharityFractional(): int
    {
        $amountFractional = (int) bcmul('100', $this->getAmount(), 2);
        return $amountFractional +
            $this->getFeeCoverAmountFractional() +
            $this->getTipAmountFractional() -
            $this->getAmountToDeductFractional();
    }

    /**
     * @return int  Full amount, including any tip or fee cover, in pence/cents/...
     */
    public function getAmountFractionalIncTip(): int
    {
        $coreAmountFractional = (int) bcmul('100', $this->getAmount(), 2);

        return
            $coreAmountFractional +
            $this->getFeeCoverAmountFractional() +
            $this->getTipAmountFractional();
    }

    /**
     * @return int  In e.g. pence/cents/...
     */
    #[Pure] public function getFeeCoverAmountFractional(): int
    {
        return (int) bcmul('100', $this->getFeeCoverAmount(), 2);
    }

    /**
     * @return int  In e.g. pence/cents/...
     */
    public function getTipAmountFractional(): int
    {
        return (int) bcmul('100', $this->getTipAmount(), 2);
    }

    /**
     * @return string
     */
    public function getOriginalPspFee(): string
    {
        return $this->originalPspFee;
    }

    /**
     * @param numeric-string $originalPspFeeFractional
     * @return void
     */
    public function setOriginalPspFeeFractional(string $originalPspFeeFractional): void
    {
        $this->originalPspFee = bcdiv($originalPspFeeFractional, '100', 2);
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * @return bool
     */
    public function hasTbgShouldProcessGiftAid(): ?bool
    {
        return $this->tbgShouldProcessGiftAid;
    }

    /**
     * @param bool $tbgShouldProcessGiftAid
     */
    public function setTbgShouldProcessGiftAid(?bool $tbgShouldProcessGiftAid): void
    {
        $this->tbgShouldProcessGiftAid = $tbgShouldProcessGiftAid;
    }

    /**
     * @return DateTime|null
     */
    public function getTbgGiftAidRequestQueuedAt(): ?DateTime
    {
        return $this->tbgGiftAidRequestQueuedAt;
    }

    /**
     * @param DateTime|null $tbgGiftAidRequestQueuedAt
     */
    public function setTbgGiftAidRequestQueuedAt(?DateTime $tbgGiftAidRequestQueuedAt): void
    {
        $this->tbgGiftAidRequestQueuedAt = $tbgGiftAidRequestQueuedAt;
    }

    /**
     * @return DateTime|null
     */
    public function getTbgGiftAidRequestFailedAt(): ?DateTime
    {
        return $this->tbgGiftAidRequestFailedAt;
    }

    /**
     * @param DateTime|null $tbgGiftAidRequestFailedAt
     */
    public function setTbgGiftAidRequestFailedAt(?DateTime $tbgGiftAidRequestFailedAt): void
    {
        $this->tbgGiftAidRequestFailedAt = $tbgGiftAidRequestFailedAt;
    }

    public function getTbgGiftAidRequestConfirmedCompleteAt(): ?DateTime
    {
        return $this->tbgGiftAidRequestConfirmedCompleteAt;
    }

    /**
     * @param DateTime|null $tbgGiftAidRequestConfirmedCompleteAt
     */
    public function setTbgGiftAidRequestConfirmedCompleteAt(?DateTime $tbgGiftAidRequestConfirmedCompleteAt): void
    {
        $this->tbgGiftAidRequestConfirmedCompleteAt = $tbgGiftAidRequestConfirmedCompleteAt;
    }

    public function getTbgGiftAidRequestCorrelationId(): ?string
    {
        return $this->tbgGiftAidRequestCorrelationId;
    }

    /**
     * @param string|null $tbgGiftAidRequestCorrelationId
     */
    public function setTbgGiftAidRequestCorrelationId(?string $tbgGiftAidRequestCorrelationId): void
    {
        $this->tbgGiftAidRequestCorrelationId = $tbgGiftAidRequestCorrelationId;
    }

    public function getTbgGiftAidResponseDetail(): ?string
    {
        return $this->tbgGiftAidResponseDetail;
    }

    /**
     * @param string|null $tbgGiftAidResponseDetail
     */
    public function setTbgGiftAidResponseDetail(?string $tbgGiftAidResponseDetail): void
    {
        $this->tbgGiftAidResponseDetail = $tbgGiftAidResponseDetail;
    }

    public function getPspCustomerId(): ?string
    {
        return $this->pspCustomerId;
    }

    public function setPspCustomerId(?string $pspCustomerId): void
    {
        $this->pspCustomerId = $pspCustomerId;
    }

    public function getPaymentMethodType(): ?PaymentMethodType
    {
        return $this->paymentMethodType;
    }

    /**
     * We want to ensure each Payment Intent is set up to be settled a specific way, so
     * we get this from an on-create property of the Donation instead of using
     * `automatic_payment_methods`.
     * "card" includes wallets and is the method for the vast majority of donations.
     * "customer_balance" is used when a previous bank transfer means a donor already
     * has credits to use for platform charities, *and* for Big Give tips set up when
     * preparing to make a bank transfer. In the latter case we rely on
     * `payment_method_options` to allow the PI to be created even though there aren't
     * yet sufficient funds.
     */
    public function getStripeMethodProperties(): array
    {
        $properties = match ($this->paymentMethodType) {
            PaymentMethodType::CustomerBalance => [
                'payment_method_types' => ['customer_balance'],
            ],
            PaymentMethodType::Card => [
                // in this case we want to use the Stripe Payment Element, so we can't specify card explicitly, we
                // need to turn on automatic methods instead and let the element decide what methods to show.
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ]
            ],
        };

        if ($this->paymentMethodType === PaymentMethodType::CustomerBalance) {
            if ($this->currencyCode !== 'GBP') {
                throw new \UnexpectedValueException('Customer balance payments only supported for GBP');
            }

            $properties['payment_method_data'] = [
                'type' => PaymentMethodType::CustomerBalance->value,
            ];

            $properties['payment_method_options'] = [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => 'gb_bank_transfer',
                    ],
                ],
            ];
        }

        return $properties;
    }

    /**
     * This isn't supported for "customer_balance" Payment Intents, and is also not
     * really needed for them because the fee is fixed at the lowest level and there
     * is no new donor bank transaction, so no statement ref to consider.
     *
     * An important side effect to keep in mind is that this means payout timing for
     * donations funded by bank transfer / customer balance is dictated by the platform
     * (Big Give) Stripe settings, *not* those of the receiving charity's connected
     * account. This means we cannot currently add a delay, so depending on the day of the
     * week it's received, a donation could be paid out to the charity almost immediately.
     * This may necessitate us having a different refund policy for donations via credit –
     * to be discussed further in early/mid 2023.
     *
     * @link https://stripe.com/docs/payments/connected-accounts
     * @link https://stripe.com/docs/connect/destination-charges#settlement-merchant
     */
    public function getStripeOnBehalfOfProperties(): array
    {
        if ($this->paymentMethodType === PaymentMethodType::Card) {
            return ['on_behalf_of' => $this->getCampaign()->getCharity()->getStripeAccountId()];
        }

        return [];
    }

    /**
     * Sidestep "`setup_future_usage` cannot be used with one or more of the values you
     * specified in `payment_method_types`. Please remove `setup_future_usage` or
     * remove these types from `payment_method_types`: ["customer_balance"]".
     */
    public function supportsSavingPaymentMethod(): bool
    {
        return $this->paymentMethodType === PaymentMethodType::Card;
    }

    public function getDonorFullName(): ?string
    {
        $firstName = $this->getDonorFirstName();
        $lastName = $this->getDonorLastName();
        if ($firstName === null && $lastName === null) {
            return null;
        }

        return trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
    }

    public function hasEnoughDataForSalesforce(): bool
    {
        return !empty($this->getDonorFirstName()) && !empty($this->getDonorLastName());
    }

    public function toClaimBotModel(): Messages\Donation
    {
        $donationMessage = new Messages\Donation();
        $donationMessage->id = $this->uuid->toString();
        $donationMessage->donation_date = $this->getCollectedAt()?->format('Y-m-d');
        $donationMessage->title = '';
        $donationMessage->first_name = $this->donorFirstName;
        $donationMessage->last_name = $this->donorLastName;

        $donationMessage->overseas = $this->donorHomePostcode === 'OVERSEAS';
        $donationMessage->postcode = $donationMessage->overseas ? '' : ($this->donorHomePostcode ?? '');

        $donationMessage->house_no = '';

        // MAT-192 will cover passing and storing this separately. For now, a pattern match should
        // give reasonable 'house number' values.
        if ($this->donorHomeAddressLine1 !== null) {
            $donationMessage->house_no = preg_replace('/^([0-9a-z-]+).*$/i', '$1', trim($this->donorHomeAddressLine1));

            // In any case where this doesn't produce a result, just send the full first 40 characters
            // of the home address. This is also HMRC's requested value in this property for overseas
            // donations.
            if (empty($donationMessage->house_no) || $donationMessage->overseas) {
                $donationMessage->house_no = trim($this->donorHomeAddressLine1);
            }

            // Regardless of which source we used and if we are aiming for a number or a full
            // address, it should be truncated at 40 characters.
            $donationMessage->house_no = mb_substr($donationMessage->house_no, 0, 40);
        }

        $donationMessage->amount = (float) $this->amount;

        $donationMessage->org_hmrc_ref = $this->getCampaign()->getCharity()->getHmrcReferenceNumber() ?? '';
        $donationMessage->org_name = $this->getCampaign()->getCharity()->getName();
        $donationMessage->org_regulator = $this->getCampaign()->getCharity()->getRegulator();
        $donationMessage->org_regulator_number = $this->getCampaign()->getCharity()->getRegulatorNumber();

        return $donationMessage;
    }

    /**
     * Make text fit in limited and possibly-required SF fields
     *
     * @param string|null $text
     * @param bool $required
     * @return string|null
     */
    private function makeSalesforceSafe(?string $text, bool $required): ?string
    {
        if ($text === null || trim($text) === '') {
            if ($required) {
                return 'N/A';
            }

            return null;
        }

        return mb_substr($text, 0, 40);
    }

    public function recordRefundAt(\DateTimeImmutable $refundDate): void
    {
        if ($this->donationStatus === DonationStatus::Refunded) {
            return;
        }
        $this->donationStatus = DonationStatus::Refunded;
        $this->refundedAt = $refundDate;
    }

    /**
     * When a donation has been partially refunded (e.g. a tip-only refund) we record the refund date but we
     * don't change the status.
     */
    public function setPartialRefundDate(\DateTimeImmutable $datetime): void
    {
        $this->refundedAt = $datetime;
    }

    public function cancel(): void
    {
        if (
            !in_array(
                $this->donationStatus,
                [
                    DonationStatus::Pending,
                    DonationStatus::Cancelled,
                    DonationStatus::Collected, // doesn't really make sense to cancel a collected donation but we have
                                               // existing unit tests doing that, not changing now.
                ],
                true
            )
        ) {
            throw new \UnexpectedValueException("Cannot cancel {$this->donationStatus->value} donation");
        }

        $this->donationStatus = DonationStatus::Cancelled;
    }

    /**
     * Updates a donation to set the appropriate fees. If card details are null then we assume for now that a card with
     * the lowest possible fees will be used, and this should be called again with the details of the selected card
     * when confirming the payment.
     *
     * @param string|null $cardBrand
     * @param string|null $cardCountry ISO two letter uppercase code
     */
    public function deriveFees(?string $cardBrand, ?string $cardCountry): void
    {
        $incursGiftAidFee = $this->hasGiftAid() && $this->hasTbgShouldProcessGiftAid();

        $fees = Calculator::calculate(
            $this->getPsp(),
            $cardBrand,
            $cardCountry,
            $this->getAmount(),
            $this->getCurrencyCode(),
            $incursGiftAidFee,
            $this->getCampaign()->getFeePercentage(),
        );

        $this->setCharityFee($fees->coreFee);
        $this->setCharityFeeVat($fees->feeVat);
    }

    public function collectFromStripeCharge(
        string $chargeId,
        string $transferId,
        ?string $cardBrand,
        ?string $cardCountry,
        string $originalFeeFractional,
        int $chargeCreationTimestamp
    ): void {
        Assertion::eq(is_null($cardBrand), is_null($cardCountry));
        Assertion::numeric($originalFeeFractional);
        Assertion::notEmpty($chargeId);
        Assertion::notEmpty($transferId);

        $this->chargeId = $chargeId;
        $this->transferId = $transferId;

        if ($cardBrand) {
            /** @psalm-var value-of<Calculator::STRIPE_CARD_BRANDS> $cardBrand */
            $this->deriveFees($cardBrand, $cardCountry);
        }

        $this->donationStatus = DonationStatus::Collected;
        $this->collectedAt = (new \DateTimeImmutable("@$chargeCreationTimestamp"));
        $this->setOriginalPspFeeFractional($originalFeeFractional);
    }

    /**
     * Updates a pending donation to reflect changes made in the donation form.
     */
    public function update(
        ?bool $giftAid,
        ?bool $tipGiftAid = null,
        ?string $donorHomeAddressLine1 = null,
        ?string $donorHomePostcode = null,
        ?DonorName $donorName = null,
        ?EmailAddress $donorEmailAddress = null,
        ?bool $tbgComms = false,
        ?bool $charityComms = false,
        ?bool $championComms = false,
        ?string $donorPostalAddress = null,
    ): void {
        if ($this->donationStatus !== DonationStatus::Pending) {
            throw new \UnexpectedValueException("Update only allowed for pending donation");
        }

        if (
            $giftAid &&
            ($donorHomeAddressLine1 === null || trim($donorHomeAddressLine1) === '')
        ) {
            throw new \UnexpectedValueException("Cannot Claim Gift Aid Without Home Address");
        }

        $this->setGiftAid($giftAid);
        $this->setTipGiftAid($tipGiftAid);
        $this->setTbgShouldProcessGiftAid($this->getCampaign()->getCharity()->isTbgClaimingGiftAid());
        $this->setDonorHomePostcode($donorHomePostcode);
        $this->setDonorHomeAddressLine1($donorHomeAddressLine1);
        $this->setDonorName($donorName);
        $this->setDonorEmailAddress($donorEmailAddress);
        $this->setTbgComms($tbgComms);
        $this->setCharityComms($charityComms);
        $this->setChampionComms($championComms);
        $this->setDonorBillingAddress($donorPostalAddress);
    }

    /**
     * Checks the donation is ready to be confirmed if and when the donor is ready to pay - i.e. that all required
     * fields are filled in.
     *
     * @throws LazyAssertionException if not.
     *
     * This method returning true does *NOT* indicate that the donor has chosen to definitely donate - that must be
     * established based on other info (e.g. because they sent a confirmation request).
     */
    public function assertIsReadyToConfirm(): true
    {
        Assert::lazy()
            ->that($this->donorFirstName, 'donorFirstName')->notNull('Missing Donor First Name')
            ->that($this->donorLastName, 'donorLastName')->notNull('Missing Donor Last Name')
            ->that($this->donorEmailAddress)->notNull('Missing Donor Email Address')
            ->that($this->donorCountryCode)->notNull('Missing Billing Country')
            ->that($this->donationStatus, 'donationStatus')
            ->eq(
                DonationStatus::Pending,
                "Donation status is '{$this->donationStatus->value}', must be 'Pending' to confirm payment"
            )
            ->verifyNow();

        return true;
    }
}
