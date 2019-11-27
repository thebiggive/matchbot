<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
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
    private $minimumAmount = 5;
    /** @var int */
    private $maximumAmount = 25000;

    /** @var string[] */
    private $possibleStatuses = [
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

    private $newStatuses = ['NotSet', 'Pending'];

    private $successStatuses = ['Collected', 'Paid'];

    /**
     * The donation ID for Charity Checkout and public APIs. Not the same as the internal auto-increment $id used
     * by Doctrine internally for fast joins.
     *
     * @ORM\Column(type="uuid", unique=true)
     * @var UuidInterface|null
     */
    protected $uuid;

    /**
     * @ORM\ManyToOne(targetEntity="Campaign")
     * @var Campaign
     */
    protected $campaign;

    /**
     * @ORM\Column(type="string", unique=true, nullable=true)
     * @var string|null Charity Checkout transaction ID assigned on their processing.
     */
    protected $transactionId;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     */
    protected $amount;

    /**
     * @ORM\Column(type="string")
     * @var string  A status, as sent by Charity Checkout verbatim.
     *              One of: NotSet, Pending, Collected, Paid, Cancelled, Refunded, Failed, Chargedback,
     *              RefundingPending, PendingCancellation.
     * @link https://docs.google.com/document/d/11ukX2jOxConiVT3BhzbUKzLfSybG8eie7MX0b0kG89U/edit?usp=sharing
     */
    protected $donationStatus = 'NotSet';

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the donor opted to receive email from the charity running the campaign
     */
    protected $charityComms;

    /**
     * @ORM\Column(type="boolean")
     * @var bool
     */
    protected $giftAid;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the donor opted to receive email from the Big Give
     */
    protected $tbgComms;

    /**
     * @ORM\Column(type="string", length=2, nullable=true)
     * @var string  Set on Charity Checkout callback
     */
    protected $donorCountryCode;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on Charity Checkout callback
     */
    protected $donorEmailAddress;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on Charity Checkout callback
     */
    protected $donorFirstName;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on Charity Checkout callback
     */
    protected $donorLastName;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string|null Set on Charity Checkout callback
     */
    protected $donorPostalAddress;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string  Amount donor chose to tip. Precision numeric string. Set on Charity Checkout callback
     */
    protected $tipAmount;

    /**
     * @ORM\OneToMany(targetEntity="FundingWithdrawal", mappedBy="donation", fetch="EAGER")
     * @var ArrayCollection|FundingWithdrawal[]
     */
    protected $fundingWithdrawals;

    public function __construct()
    {
        $this->fundingWithdrawals = new ArrayCollection();
    }

    /**
     * @ORM\PrePersist Check that the amount is in the permitted range
     */
    public function prePersist(): void
    {
        // Decimal-safe check that amount if in the allowed range
        if (
            bccomp($this->amount, (string) $this->minimumAmount, 2) === -1 ||
            bccomp($this->amount, (string) $this->maximumAmount, 2) === 1
        ) {
            throw new \UnexpectedValueException("Amount must be £{$this->minimumAmount}-{$this->maximumAmount}");
        }
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
        $data = $this->toApiModel(false);

        $data['createdTime'] = $this->getCreatedDate()->format(DateTime::ATOM);
        $data['updatedTime'] = $this->getUpdatedDate()->format(DateTime::ATOM);
        $data['amountMatchedByChampionFunds'] = (float) $this->getConfirmedChampionWithdrawalTotal();
        $data['amountMatchedByPledges'] = (float) $this->getConfirmedPledgeWithdrawalTotal();

        unset(
            $data['charityName'],
            $data['donationId'],
            $data['matchReservedAmount'],
            $data['matchedAmount'],
            $data['optInCharityEmail']
        );

        return $data;
    }

    public function toApiModel($create = true): array
    {
        // We omit `donationId` and let Salesforce set its own, which we then persist back to the MatchBot DB on
        // success.
        $data = [
            'charityId' => $this->getCampaign()->getCharity()->getDonateLinkId(),
            'charityName' => $this->getCampaign()->getCharity()->getName(),
            'donationAmount' => (float) $this->getAmount(),
            'donationId' => $this->getUuid(),
            'donationMatched' => $this->getCampaign()->isMatched(),
            'giftAid' => $this->isGiftAid(),
            'matchReservedAmount' => 0,
            'optInCharityEmail' => $this->getCharityComms(),
            'optInTbgEmail' => $this->getTbgComms(),
            'projectId' => $this->getCampaign()->getSalesforceId(),
            'status' => $this->getDonationStatus(),
        ];

        if (in_array($this->getDonationStatus(), ['Pending', 'Reserved'], true)) {
            $data['matchReservedAmount'] = (float) $this->getFundingWithdrawalTotal();
        }

        if (!$create) {
            $data['billingPostalAddress'] = $this->getDonorPostalAddress();
            $data['countryCode'] = $this->getDonorCountryCode();
            $data['emailAddress'] = $this->getDonorEmailAddress();
            $data['firstName'] = $this->getDonorFirstName();
            $data['lastName'] = $this->getDonorLastName();
            $data['transactionId'] = $this->getTransactionId();
            $data['matchedAmount'] = $this->isSuccessful() ? (float) $this->getFundingWithdrawalTotal() : 0;
            $data['tipAmount'] = (float) $this->getTipAmount();
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
        return in_array($this->donationStatus, $this->successStatuses, true);
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

    /**
     * @param string $donorEmailAddress
     */
    public function setDonorEmailAddress(string $donorEmailAddress): void
    {
        $this->donorEmailAddress = $donorEmailAddress;
    }

    /**
     * @return bool
     */
    public function getCharityComms(): bool
    {
        return $this->charityComms;
    }

    /**
     * @param bool $charityComms
     */
    public function setCharityComms(bool $charityComms): void
    {
        $this->charityComms = $charityComms;
    }

    public function getDonorFirstName(): ?string
    {
        return $this->donorFirstName;
    }

    /**
     * @param string $donorFirstName
     */
    public function setDonorFirstName(string $donorFirstName): void
    {
        $this->donorFirstName = $donorFirstName;
    }

    public function getDonorLastName(): ?string
    {
        return $this->donorLastName;
    }

    /**
     * @param string $donorLastName
     */
    public function setDonorLastName(string $donorLastName): void
    {
        $this->donorLastName = $donorLastName;
    }

    public function getDonorPostalAddress(): ?string
    {
        return $this->donorPostalAddress;
    }

    /**
     * @param string $donorPostalAddress
     */
    public function setDonorPostalAddress(string $donorPostalAddress): void
    {
        $this->donorPostalAddress = $donorPostalAddress;
    }

    /**
     * @return bool
     */
    public function isGiftAid(): bool
    {
        return $this->giftAid;
    }

    /**
     * @param bool $giftAid
     */
    public function setGiftAid(bool $giftAid): void
    {
        $this->giftAid = $giftAid;
    }

    /**
     * @return bool
     */
    public function getTbgComms(): bool
    {
        return $this->tbgComms;
    }

    /**
     * @param bool $tbgComms
     */
    public function setTbgComms(bool $tbgComms): void
    {
        $this->tbgComms = $tbgComms;
    }

    /**
     * @return string
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @param string $amount
     */
    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    /**
     * @param string $transactionId
     */
    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
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

    /**
     * @return bool Whether the donation has a hook-updated status and should therefore be updated in Salesforce after
     *              creation, if successful SF create doesn't happen before MatchBot processes the hook.
     */
    public function hasPostCreateUpdates(): bool
    {
        return !in_array($this->getDonationStatus(), $this->newStatuses, true);
    }
}
