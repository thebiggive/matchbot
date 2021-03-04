<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
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
    use TimestampsTrait;

    /** @var int */
    private int $minimumAmount = 1;
    /** @var int */
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

    private array $possiblePSPs = ['enthuse', 'stripe'];

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
     * Core donation amount excluding any tip.
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected string $amount;

    /**
     * Fee the charity takes on, in £. Excludes any tax if applicable.
     *
     * For Enthuse: 1.9% of $amount + 0.20p
     * For Stripe (EU / UK): 1.5% of $amount + 0.20p
     * For Stripe (Non EU / Amex): 3.2% of $amount + 0.20p
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected string $charityFee = '0.00';

    /**
     * Value Added Tax amount on `$charityFee`, in £. In addition to base amount
     * in $charityFee.
     *
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected string $charityFeeVat = '0.00';

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
     * @var string|null  Set on PSP callback
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
     * @var string  Amount donor chose to tip. Precision numeric string.
     *              Set during setup when using Stripe, and on Enthuse callback otherwise.
     */
    protected string $tipAmount = '0.00';

    /**
     * @ORM\Column(type="boolean", nullable=true)
     * @var bool    Whether Gift Aid was claimed on the 'tip' donation to the Big Give.
     */
    protected ?bool $tipGiftAid = null;

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

        $data['createdTime'] = $this->getCreatedDate()->format(DateTime::ATOM);
        $data['updatedTime'] = $this->getUpdatedDate()->format(DateTime::ATOM);
        $data['amountMatchedByChampionFunds'] = (float) $this->getConfirmedChampionWithdrawalTotal();
        $data['amountMatchedByPledges'] = (float) $this->getConfirmedPledgeWithdrawalTotal();

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
            'createdTime' => $this->getCreatedDate()->format(DateTime::ATOM),
            'donationAmount' => (float) $this->getAmount(),
            'donationId' => $this->getUuid(),
            'donationMatched' => $this->getCampaign()->isMatched(),
            'emailAddress' => $this->getDonorEmailAddress(),
            'firstName' => $this->getDonorFirstName() === null
                ? null
                : substr($this->getDonorFirstName(), 0, 40), // fit within SF limit
            'giftAid' => $this->hasGiftAid(),
            'homeAddress' => $this->getDonorHomeAddressLine1(),
            'homePostcode' => $this->getDonorHomePostcode(),
            'lastName' => $this->getDonorLastName(),
            'matchedAmount' => $this->isSuccessful() ? (float) $this->getFundingWithdrawalTotal() : 0,
            'matchReservedAmount' => 0,
            'optInCharityEmail' => $this->getCharityComms(),
            'optInChampionEmail' => $this->getChampionComms(),
            'optInTbgEmail' => $this->getTbgComms(),
            'projectId' => $this->getCampaign()->getSalesforceId(),
            'psp' => $this->getPsp(),
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

    /**
     * @param string $donationStatus
     */
    public function setDonationStatus(string $donationStatus): void
    {
        if (!in_array($donationStatus, $this->possibleStatuses, true)) {
            throw new \UnexpectedValueException("Unexpected status '$donationStatus'");
        }

        $this->donationStatus = $donationStatus;
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

    public function getDonorFirstName(): ?string
    {
        return $this->donorFirstName;
    }

    public function setDonorFirstName(?string $donorFirstName): void
    {
        $this->donorFirstName = $donorFirstName;
    }

    public function getDonorLastName(): ?string
    {
        return $this->donorLastName;
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
     * Get core donation amount excluding any tip.
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
            throw new \UnexpectedValueException("Amount must be £{$this->minimumAmount}-{$this->maximumAmount}");
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
    public function getTipAmount(): string
    {
        return $this->tipAmount;
    }

    /**
     * @param string $tipAmount
     */
    public function setTipAmount(string $tipAmount): void
    {
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
     * @return int      The amount of the total donation, in pence, which is to be excluded
     *                  from payout to the charity. This is the sum of
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     */
    public function getAmountToDeductInPence(): int
    {
        $amountToDeduct = bcadd($this->getTipAmount(), $this->getCharityFeeGross(), 2);

        return (int) bcmul('100', $amountToDeduct, 2);
    }

    /**
     * @return int      The amount of the core donation, in pence, which is to be paid out
     *                  to the charity. This is the amount paid by the donor minus
     *                  (a) any part of that amount which was a tip to the Big Give;
     *                  (b) fees on the remaining donation amount; and
     *                  (c) VAT on fees where applicable.
     *                  It does not include separately sourced funds like matching or
     *                  Gift Aid.
     *
     *                  This is just used in unit tests to validate we haven't broken anything now.
     *                  Note that because `getAmountToDeductInPence()` takes off the tip amount and
     *                  `getAmount()` relates to core amount, we must re-add the tip here to get a
     *                  correct answer.
     */
    public function getAmountForCharityInPence(): int
    {
        $amountInPence = (int) bcmul('100', $this->getAmount(), 2);
        return $amountInPence - $this->getAmountToDeductInPence() + $this->getTipAmountInPence();
    }

    /**
     * @return string[]
     */
    public static function getSuccessStatuses(): array
    {
        return self::$successStatuses;
    }

    /**
     * @return int  Full amount, including any tip, in pence.
     */
    public function getAmountInPenceIncTip(): int
    {
        $coreAmountInPence = (int) bcmul('100', $this->getAmount(), 2);

        return $coreAmountInPence + $this->getTipAmountInPence();
    }

    public function getTipAmountInPence(): int
    {
        return (int) bcmul('100', $this->getTipAmount(), 2);
    }

    public function hasEnoughDataForSalesforce(): bool
    {
        return !empty($this->getDonorFirstName()) && !empty($this->getDonorLastName());
    }
}
