<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table]
#[ORM\Index(name: 'end_date_and_is_matched', columns: ['endDate', 'isMatched'])]
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Campaign extends SalesforceReadProxy
{
    use TimestampsTrait;

    /**
     * @var Charity
     */
    #[ORM\ManyToOne(targetEntity: Charity::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'charity_id', referencedColumnName: 'id')]
    protected Charity $charity;

    /**
     * @var string  ISO 4217 code for the currency in which donations can be accepted and matching's organised.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected ?string $currencyCode;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    /**
     * @var DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected DateTime $startDate;

    /**
     * @var DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected DateTime $endDate;

    /**
     * @var string|null
     * @psalm-var numeric-string|null
     */
    #[ORM\Column(type: 'decimal', nullable: true, precision: 3, scale: 1)]
    protected ?string $feePercentage = null;

    /**
     * @var bool    Whether the Campaign has any match funds
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $isMatched;

    /**
     * Every campaign must have a charity, but we pass null when we don't know the charity because
     * the campaign is just a near empty placeholder to be filled by a pull from Salesforce.
     */
    public function __construct(?Charity $charity)
    {
        if ($charity) {
            $this->charity = $charity;
        }
    }

    /**
     * Implemented only so this can be cast to string if required for logging etc - not for use in any business process.
     */
    public function __toString(): string
    {
        return "Campaign ID #{$this->id}, SFId: {$this->salesforceId}";
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    #[ORM\PrePersist]
    public function prePersistCheck(PrePersistEventArgs $_args): void
    {
        try {
            // PHP doesn't have a much nicer way to check if a property is initialised because the maintainers think
            // all typed properties should be initialised by end of constructor so we shouldn't need to check.
            // https://externals.io/message/114607
            //
            // I previously tried enforcing this at the DB level but that migration wouldn't run in staging or reg
            // envrionments

            $_charity = $this->charity;
        } catch (\Error $e) {
            throw new \Exception("Error on attempt to persist campaign #{$this->id}, sfID {$this->salesforceId}: \n{$e}");
        }
    }

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

    /**
     * @psalm-return numeric-string|null
     */
    public function getFeePercentage(): ?string
    {
        return $this->feePercentage;
    }

    /**
     * @psalm-param numeric-string|null $feePercentage
     */
    public function setFeePercentage(?string $feePercentage): void
    {
        $this->feePercentage = $feePercentage;
    }

    /**
     * @return DateTime
     */
    public function getEndDate(): DateTime
    {
        return $this->endDate;
    }
}
