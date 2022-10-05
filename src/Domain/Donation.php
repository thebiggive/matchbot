<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use Messages;
use Ramsey\Uuid\UuidInterface;

/**
 * @ORM\Entity(repositoryClass="DonationRepository")
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table(indexes={
 *   @ORM\Index(name="date_and_status", columns={"createdAt", "donationStatus"}),
 *   @ORM\Index(name="salesforcePushStatus", columns={"salesforcePushStatus"}),
 * })
 */
class Donation extends SalesforceWriteProxy
{
    /**
     * @var int
     * @see Donation::$currencyCode
     */
    private int $minimumAmount = 1;

    /**
     * @var int
     * @see Donation::$currencyCode
     */
    private int $maximumAmount = 25000;

    /** @var string[] */
    private array $possibleStatuses = [
        'Pending',
        'Collected',
        'Paid',
        'Cancelled',
        'Refunded',
        'Failed',
        'Chargedback',
        'RefundingPending',
        'PendingCancellation',
    ];

    private array $possiblePSPs = ['stripe'];

    private array $newStatuses = ['NotSet', 'Pending'];

    private static array $successStatuses = ['Collected', 'Paid'];

    /**
     * @link https://thebiggive.slack.com/archives/GGQRV08BZ/p1576070168066200?thread_ts=1575655432.161800&cid=GGQRV08BZ
     */
    private static array $reversedStatuses = ['Refunded', 'Failed', 'Chargedback'];

    /**
     * The donation ID for PSPs and public APIs. Not the same as the internal auto-increment $id used
     * by Doctrine internally for fast joins.
     *
     * @ORM\Column(type="uuid", unique=true)
     * @var UuidInterface|null
     */
    protected ?UuidInterface $uuid = null;

    /**
     * @ORM\ManyToOne(targetEntity="Campaign")
     * @var Campaign
     */
    protected Campaign $campaign;

    /**
     * @ORM\Column(type="string", length=20)
     * @var string  Which Payment Service Provider (PSP) is expected to (or did) process the donation.
     */
    protected string $psp;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var ?DateTime    When the donation first moved to status Collected, i.e. the donor finished paying.
     */
    protected ?DateTime $collectedAt = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string  Token for the client to complete payment, set by PSPs like Stripe for Payment Intents.
     */
    protected ?string $clientSecret = null;

    /**
     * @ORM\Column(type="string", unique=true, nullable=true)
     * @var string|null PSP's transaction ID assigned on their processing.
     */
    protected ?string $transactionId = null;

    /**
     * @ORM\Column(type="string", unique=true, nullable=true)
     * @var string|null PSP's charge ID assigned on their processing.
     */
    protected ?string $chargeId = null;

    /**
     * @ORM\Column(type="string", unique=true, nullable=true)
     * @var string|null PSP's transfer ID assigned on a successful charge. For Stripe this
     *                  ID relates to the Platform Account (i.e. the Big Give's) rather than
     *                  the Connected Account for the charity receiving the transferred
     *                  donation balance.
     */
    protected ?string $transferId = null;

    /**
     * @ORM\Column(type="string", length=3)
     * @var string  ISO 4217 code for the currency in which all monetary values are denominated, e.g. 'GBP'.
     */
    protected string $currencyCode;

    /**
     * Core donation amount excluding any tip.
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    protected string $amount;

    /**
     * Fee the charity takes on, in £. Excludes any tax if applicable.
     *
     * For Stripe (EU / UK): 1.5% of $amount + 0.20p
     * For Stripe (Non EU / Amex): 3.2% of $amount + 0.20p
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    protected string $charityFee = '0.00';

    /**
     * Value Added Tax amount on `$charityFee`, in £. In addition to base amount
     * in $charityFee.
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    protected string $charityFeeVat = '0.00';

    /**
     * Fee charged to TBG by the PSP, in £. Added just for Stripe transactions from March '21.
     * Original fee varies by card brand and country and is based on the full amount paid by the
     * donor: `$amount + $tipAmount`.
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Donation::$currencyCode
     */
    protected string $originalPspFee = '0.00';

    /**
     * @ORM\Column(type="string")
     * @var string  A status, as sent by the PSP verbatim.
     * @todo Consider vs. Stripe options
     *              One of: NotSet, Pending, Collected, Paid, Cancelled, Refunded, Failed, Chargedback,
     *              RefundingPending, PendingCancellation.
     * @link https://docs.google.com/document/d/11ukX2jOxConiVT3BhzbUKzLfSybG8eie7MX0b0kG89U/edit?usp=sharing
     */
    protected string $donationStatus = 'NotSet';

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether the donor opted to receive email from the charity running the campaign
     */
    protected ?bool $charityComms = null;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool
     */
    protected ?bool $giftAid = null;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether the donor opted to receive email from the Big Give
     */
    protected ?bool $tbgComms = null;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether the donor opted to receive email from the champion funding the campaign
     */
    protected ?bool $championComms = null;

    /**
     * @ORM\Column(type="string", length=2, nullable=true)
     * @var string|null  Set on PSP callback. *Billing* country code.
     */
    protected ?string $donorCountryCode = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on PSP callback
     */
    protected ?string $donorEmailAddress = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on PSP callback
     */
    protected ?string $donorFirstName = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on PSP callback
     */
    protected ?string $donorLastName = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Assumed to be billing address going forward.
     */
    protected ?string $donorPostalAddress = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    protected ?string $donorHomeAddressLine1 = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null From residential address, if donor is claiming Gift Aid.
     */
    protected ?string $donorHomePostcode = null;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string  Amount donor chose to add to cover a fee, including any tax.
     *              Precision numeric string.
     * @see Donation::$currencyCode
     */
    protected string $feeCoverAmount = '0.00';

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string  Amount donor chose to tip. Precision numeric string.
     *              Set during donation setup and can also be modified later if the donor changes only this.
     * @see Donation::$currencyCode
     */
    protected string $tipAmount = '0.00';

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether Gift Aid was claimed on the 'tip' donation to the Big Give.
     */
    protected ?bool $tipGiftAid = null;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether any Gift Aid claim should be made by the Big Give as an agent/nominee
     *              *if* `$giftAid is true too. This field is set independently to allow for claim
     *              status amendments so we must not assume a donation can actualy be claimed just
     *              because it's true.
     * @see Donation::$giftAid
     */
    protected ?bool $tbgShouldProcessGiftAid = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var ?DateTime   When a queued message that should lead to a Gift Aid claim was sent.
     */
    protected ?DateTime $tbgGiftAidRequestQueuedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var ?DateTime   When a claim submission attempt was detected to have an error returned.
     */
    protected ?DateTime $tbgGiftAidRequestFailedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var ?DateTime   When a claim was detected accepted via an async poll.
     */
    protected ?DateTime $tbgGiftAidRequestConfirmedCompleteAt = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var ?string Provided by HMRC upon initial claim submission acknowledgement.
     *              Doesn't imply success.
     */
    protected ?string $tbgGiftAidRequestCorrelationId = null;

    /**
     * @ORM\Column(type="text", length=65535, nullable=true)
     * @var ?string Verbatim final errors or messages from HMRC received immediately or
     *              (most likely based on real world observation) via an async poll.
     */
    protected ?string $tbgGiftAidResponseDetail = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected ?string $pspCustomerId = null;

    /**
     * @ORM\OneToMany(targetEntity="FundingWithdrawal", mappedBy="donation", fetch="EAGER")
     * @var ArrayCollection|FundingWithdrawal[]
     */
    protected $fundingWithdrawals;

    public function __construct()
    {
        $this->fundingWithdrawals = new ArrayCollection();
    }

    public function __toString()
    {
        return "Donation {$this->getUuid()} to {$this->getCampaign()->getCharity()->getName()}";
    }

    /**
     * @ORM\PreUpdate Check that the amount is never changed
     * @param PreUpdateEventArgs $args
     * @throws \LogicException if amount is changed
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('amount')) {
            return;
        }

        if ($args->getOldValue('amount') !== $args->getNewValue('amount')) {
            throw new \LogicException('Amount may not be changed after a donation is created');
        }
    }

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

        unset(
            $data['clientSecret'],
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
            'clientSecret' => $this->getClientSecret(),
            'charityFee' => (float) $this->getCharityFee(),
            'charityFeeVat' => (float) $this->getCharityFeeVat(),
            'charityId' => $this->getCampaign()->getCharity()->getDonateLinkId(),
            'charityName' => $this->getCampaign()->getCharity()->getName(),
            'countryCode' => $this->getDonorCountryCode(),
            'collectedTime' => $this->getCollectedAt()?->format(DateTimeInterface::ATOM),
            'createdTime' => $this->getCreatedDate()->format(DateTimeInterface::ATOM),
            'currencyCode' => $this->getCurrencyCode(),
            'donationAmount' => (float) $this->getAmount(),
            'donationId' => $this->getUuid(),
            'donationMatched' => $this->getCampaign()->isMatched(),
            'emailAddress' => $this->getDonorEmailAddress(),
            'feeCoverAmount' => (float) $this->getFeeCoverAmount(),
            'firstName' => $this->getDonorFirstName(true),
            'giftAid' => $this->hasGiftAid(),
            'homeAddress' => $this->getDonorHomeAddressLine1(),
            'homePostcode' => $this->getDonorHomePostcode(),
            'lastName' => $this->getDonorLastName(true),
            'matchedAmount' => $this->isSuccessful() ? (float) $this->getFundingWithdrawalTotal() : 0,
            'matchReservedAmount' => 0,
            'optInCharityEmail' => $this->getCharityComms(),
            'optInChampionEmail' => $this->getChampionComms(),
            'optInTbgEmail' => $this->getTbgComms(),
            'projectId' => $this->getCampaign()->getSalesforceId(),
            'psp' => $this->getPsp(),
            'pspCustomerId' => $this->getPspCustomerId(),
            'status' => $this->getDonationStatus(),
            'tipAmount' => (float) $this->getTipAmount(),
            'tipGiftAid' => $this->hasTipGiftAid(),
            'transactionId' => $this->getTransactionId(),
        ];

        if (in_array($this->getDonationStatus(), ['Pending', 'Reserved'], true)) {
            $data['matchReservedAmount'] = (float) $this->getFundingWithdrawalTotal();
        }

        return $data;
    }

    /**
     * @return bool Whether this donation is *currently* in a state that we consider to be successful.
     *              Note that this is not guaranteed to be permanent: donations can be refunded or charged back after
     *              being in a state where this method is `true`.
     */
    public function isSuccessful(): bool
    {
        return in_array($this->donationStatus, self::$successStatuses, true);
    }

    /**
     * @return bool Whether this donation is in a reversed / failed state.
     */
    public function isReversed(): bool
    {
        return in_array($this->donationStatus, self::$reversedStatuses, true);
    }

    /**
     * @return string
     */
    public function getDonationStatus(): string
    {
        return $this->donationStatus;
    }

    public function setDonationStatus(string $donationStatus): void
    {
        if (!in_array($donationStatus, $this->possibleStatuses, true)) {
            throw new \UnexpectedValueException("Unexpected status '$donationStatus'");
        }

        $this->donationStatus = $donationStatus;
    }

    public function getCollectedAt(): ?DateTime
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(?DateTime $collectedAt): void
    {
        $this->collectedAt = $collectedAt;
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

    public function getDonorEmailAddress(): ?string
    {
        return $this->donorEmailAddress;
    }

    public function setDonorEmailAddress(?string $donorEmailAddress): void
    {
        $this->donorEmailAddress = $donorEmailAddress;
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

    public function setDonorFirstName(?string $donorFirstName): void
    {
        $this->donorFirstName = $donorFirstName;
    }

    public function getDonorLastName(bool $salesforceSafe = false): ?string
    {
        $lastName = $this->donorLastName;

        if ($salesforceSafe) {
            $lastName = $this->makeSalesforceSafe($lastName, true);
        }

        return $lastName;
    }

    public function setDonorLastName(?string $donorLastName): void
    {
        $this->donorLastName = $donorLastName;
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

    public function setGiftAid(?bool $giftAid): void
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

    /**
     * @param string $amount    Core donation amount, excluding any tip, in full pounds GBP.
     */
    public function setAmount(string $amount): void
    {
        if (
            bccomp($amount, (string) $this->minimumAmount, 2) === -1 ||
            bccomp($amount, (string) $this->maximumAmount, 2) === 1
        ) {
            throw new \UnexpectedValueException(sprintf(
                'Amount must be %d-%d %s',
                $this->minimumAmount,
                $this->maximumAmount,
                $this->currencyCode,
            ));
        }

        $this->amount = $amount;
    }

    public function setCharityFee(string $charityFee): void
    {
        $this->charityFee = $charityFee;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
    }

    public function setChargeId(string $chargeId): void
    {
        $this->chargeId = $chargeId;
    }

    /**
     * @return string|null
     */
    public function getTransferId(): ?string
    {
        return $this->transferId;
    }

    /**
     * @param string|null $transferId
     */
    public function setTransferId(?string $transferId): void
    {
        $this->transferId = $transferId;
    }

    public function getDonorCountryCode(): ?string
    {
        return $this->donorCountryCode;
    }

    /**
     * @return string   Total amount in withdrawals - not necessarily finalised.
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
    public function getConfirmedChampionWithdrawalTotal(): string
    {
        if (!$this->isSuccessful()) {
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
    public function getConfirmedPledgeWithdrawalTotal(): string
    {
        if (!$this->isSuccessful()) {
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

    public function getTransactionId(): ?string
    {
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
     * @return ArrayCollection|FundingWithdrawal[]
     */
    public function getFundingWithdrawals()
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
    public function setPsp(string $psp): void
    {
        if (!in_array($psp, $this->possiblePSPs, true)) {
            throw new \UnexpectedValueException("Unexpected PSP '$psp'");
        }

        $this->psp = $psp;
    }

    /**
     * @return string
     */
    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    /**
     * @param string $clientSecret
     */
    public function setClientSecret(string $clientSecret): void
    {
        $this->clientSecret = $clientSecret;
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
     */
    public function setFeeCoverAmount(string $feeCoverAmount): void
    {
        $this->feeCoverAmount = $feeCoverAmount;
    }

    /**
     * @return string
     */
    public function getTipAmount(): string
    {
        return $this->tipAmount;
    }

    /**
     * @param string $tipAmount
     */
    public function setTipAmount(string $tipAmount): void
    {
        if (bccomp($tipAmount, (string) $this->maximumAmount, 2) === 1) {
            throw new \UnexpectedValueException(sprintf(
                'Tip amount must not exceed %d %s',
                $this->maximumAmount,
                $this->currencyCode,
            ));
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

    public function getDonorHomeAddressLine1(): ?string
    {
        return $this->donorHomeAddressLine1;
    }

    public function setDonorHomeAddressLine1(?string $donorHomeAddressLine1): void
    {
        $this->donorHomeAddressLine1 = $donorHomeAddressLine1;
    }

    public function getDonorHomePostcode(): ?string
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
        return !in_array($this->getDonationStatus(), $this->newStatuses, true);
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
     * @return string[]
     */
    public static function getSuccessStatuses(): array
    {
        return self::$successStatuses;
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

    public function setOriginalPspFeeFractional(int $originalPspFeeFractional): void
    {
        $this->originalPspFee = bcdiv((string) $originalPspFeeFractional, '100', 2);
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
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

    public function hasEnoughDataForSalesforce(): bool
    {
        return !empty($this->getDonorFirstName()) && !empty($this->getDonorLastName());
    }

    public function isNew(): bool
    {
        return in_array($this->donationStatus, $this->newStatuses, true);
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
        $donationMessage->org_name = $this->getCampaign()->getCharity()->getName() ?? '';
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
}
