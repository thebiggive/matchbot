<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Persistence\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 * @todo copy this type of auto timstamp for ALL OTHER entities
 */
class Donation
{
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

    // TODO give these UUIDs?
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var int
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="Campaign")
     * @var Campaign
     */
    protected $campaign;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $createdDate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $updatedDate;

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var
     */
    protected $amount;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the donor opted to receive email from the charity running the campaign
     */
    protected $charityComms;

    /**
     * @ORM\Column(type="string")
     * @var string  A status, as sent by Charity Checkout verbatim. @todo document better
     */
    protected $donationStatus;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $donorEmailAddress;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $donorFirstName;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $donorLastName;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $donorPostalAddress;

    /**
     * @ORM\Column(type="string")
     * @var string  e.g. Mx, ...
     */
    protected $donorTitle;

    /**
     * @ORM\Column(type="boolean")
     * @var bool
     */
    protected $giftAid;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $salesforcePushDate;

    /**
     * @ORM\Column(type="string")
     * @var string  One of 'not-sent', 'pending' or 'complete'
     */
    protected $salesforcePushStatus;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the donor opted to receive email from the Big Give
     */
    protected $tbgComms;

    /**
     * @ORM\PrePersist Check that the amount is in the permitted range; set created + updated timestamps
     */
    public function prePersist(): void
    {
        if ($this->amount < $this->minimumAmount || $this->amount > $this->maximumAmount) {
            throw new \UnexpectedValueException("Amount must be £{$this->minimumAmount}-{$this->maximumAmount}");
        }
        $this->createdDate = new \DateTime('now');
        $this->updatedDate = new \DateTime('now');
    }

    /**
     * @ORM\PreUpdate Check that the amount is never changed; set updated timestamp
     * @param PreUpdateEventArgs $args
     * @throws \LogicException if amount is changed
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getOldValue('amount') !== $args->getNewValue('amount')) {
            throw new \LogicException('Amount may not be changed after a donation is created');
        }
        $this->updatedDate = new \DateTime('now');
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
     * @return DateTime
     */
    public function getCreatedDate(): DateTime
    {
        return $this->createdDate;
    }

    /**
     * @return DateTime
     */
    public function getUpdatedDate(): DateTime
    {
        return $this->updatedDate;
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
     * @return DateTime
     */
    public function getSalesforcePushDate(): DateTime
    {
        return $this->salesforcePushDate;
    }

    /**
     * @param DateTime $salesforcePushDate
     */
    public function setSalesforcePushDate(DateTime $salesforcePushDate): void
    {
        $this->salesforcePushDate = $salesforcePushDate;
    }

    /**
     * @return string
     */
    public function getSalesforcePushStatus(): string
    {
        return $this->salesforcePushStatus;
    }

    /**
     * @param string $salesforcePushStatus
     */
    public function setSalesforcePushStatus(string $salesforcePushStatus): void
    {
        $this->salesforcePushStatus = $salesforcePushStatus;
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
}
