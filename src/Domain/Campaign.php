<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="CampaignRepository")
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 *
 * Represents any Campaign type in Salesforce which can receive donations. Note that this does NOT include Master
 * record type(s). The only way Salesforce type impacts this model is in setting `$isMatched` appropriately.
 */
class Campaign extends SalesforceReadProxy
{
    use TimestampsTrait;

    /**
     * @ORM\ManyToOne(targetEntity="Charity", cascade={"persist"})
     * @var Charity
     */
    protected Charity $charity;

    /**
     * @ORM\Column(type="string", length=3)
     * @var string  ISO 4217 code for the currency in which donations can be accepted and matching's organised.
     */
    protected ?string $currencyCode;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected string $name;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected DateTime $startDate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected DateTime $endDate;

    /**
     * @ORM\Column(type="decimal", nullable=true, precision=3, scale=1)
     * @var float|null
     */
    protected ?float $feePercentage = null;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the Campaign has any match funds
     */
    protected bool $isMatched;

    /**
     * @return bool
     */
    public function isMatched(): bool
    {
        return $this->isMatched;
    }

    /**
     * @param Charity $charity
     */
    public function setCharity(Charity $charity): void
    {
        $this->charity = $charity;
    }

    /**
     * @param bool $isMatched
     */
    public function setIsMatched(bool $isMatched): void
    {
        $this->isMatched = $isMatched;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @param DateTime $startDate
     */
    public function setStartDate(DateTime $startDate): void
    {
        $this->startDate = $startDate;
    }

    /**
     * @param DateTime $endDate
     */
    public function setEndDate(DateTime $endDate): void
    {
        $this->endDate = $endDate;
    }

    /**
     * @return Charity
     */
    public function getCharity(): Charity
    {
        return $this->charity;
    }

    /**
     * @return string
     */
    public function getCampaignName(): string
    {
        return $this->name;
    }

    public function isOpen(): bool
    {
        return ($this->startDate <= new DateTime('now') && $this->endDate > new DateTime('now'));
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getFeePercentage(): ?float
    {
        return $this->feePercentage;
    }

    public function setFeePercentage(?float $feePercentage): void
    {
        $this->feePercentage = $feePercentage;
    }
}
