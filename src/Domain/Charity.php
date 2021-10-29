<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="CharityRepository")
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
class Charity extends SalesforceReadProxy
{
    use TimestampsTrait;

    /**
     * @ORM\Column(type="string")
     * @var string  The ID PSPs expect us to identify the charity by. Currently matches
     *              `$id` for new charities but has a numeric value for those imported from the
     *              legacy database.
     */
    protected string $donateLinkId;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected string $name;

    /**
     * @ORM\Column(type="string", length=255, unique=true, nullable=true)
     * @var string
     */
    protected ?string $stripeAccountId = null;

    /**
     * @ORM\Column(type="string", length=7, unique=true, nullable=true)
     * @var ?string
     */
    protected ?string $hmrcReferenceNumber = null;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the charity's Gift Aid is currently to be claimed by the Big Give.
     */
    protected bool $tbgClaimingGiftAid = false;

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function getDonateLinkId(): string
    {
        return $this->donateLinkId;
    }

    public function setDonateLinkId(string $donateLinkId): void
    {
        $this->donateLinkId = $donateLinkId;
    }

    /**
     * @return string|null
     */
    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    /**
     * @param string|null $stripeAccountId
     */
    public function setStripeAccountId(?string $stripeAccountId): void
    {
        $this->stripeAccountId = $stripeAccountId;
    }

    /**
     * @return bool
     */
    public function isTbgClaimingGiftAid(): bool
    {
        return $this->tbgClaimingGiftAid;
    }

    /**
     * @param bool $tbgClaimingGiftAid
     */
    public function setTbgClaimingGiftAid(bool $tbgClaimingGiftAid): void
    {
        $this->tbgClaimingGiftAid = $tbgClaimingGiftAid;
    }

    /**
     * @return string|null
     */
    public function getHmrcReferenceNumber(): ?string
    {
        return $this->hmrcReferenceNumber;
    }

    /**
     * @param string|null $hmrcReferenceNumber
     */
    public function setHmrcReferenceNumber(?string $hmrcReferenceNumber): void
    {
        $this->hmrcReferenceNumber = $hmrcReferenceNumber;
    }
}
