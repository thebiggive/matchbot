<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Persistence\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * @ORM\Entity(repositoryClass="DonationRepository")
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
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

    private $successStatuses = ['Collected', 'Paid'];

    /**
     * The donation ID for Charity Checkout and public APIs.
     *
     * @ORM\Column(type="uuid", unique=true)
     * @ORM\GeneratedValue(strategy="CUSTOM")
     * @ORM\CustomIdGenerator(class="Ramsey\Uuid\Doctrine\UuidGenerator")
     * @var UuidInterface
     */
    protected $uuid;

    /**
     * @ORM\ManyToOne(targetEntity="Campaign")
     * @var Campaign
     */
    protected $campaign;

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
     * @ORM\Column(type="string", nullable=true)
     * @var string  Set on Charity Checkout callback
     */
    protected $donorEmailAddress;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string  Set on Charity Checkout callback
     */
    protected $donorFirstName;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string  Set on Charity Checkout callback
     */
    protected $donorLastName;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string  Set on Charity Checkout callback
     */
    protected $donorPostalAddress;

    /**
     * @ORM\Column(type="string", nullable=true)
     * @var string  e.g. Mx, ... Set on Charity Checkout callback
     */
    protected $donorTitle;

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
        if ($args->getOldValue('amount') !== $args->getNewValue('amount')) {
            throw new \LogicException('Amount may not be changed after a donation is created');
        }
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

    /**
     * @return string
     */
    public function getDonorEmailAddress(): string
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

    /**
     * @return string
     */
    public function getDonorFirstName(): string
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

    /**
     * @return string
     */
    public function getDonorLastName(): string
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

    /**
     * @return string
     */
    public function getDonorPostalAddress(): string
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
     * @return string
     */
    public function getDonorTitle(): string
    {
        return $this->donorTitle;
    }

    /**
     * @param string $donorTitle
     */
    public function setDonorTitle(string $donorTitle): void
    {
        $this->donorTitle = $donorTitle;
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
}
