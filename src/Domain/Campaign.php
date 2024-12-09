<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;

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
     * @psalm-suppress PossiblyUnusedProperty Used in Doctrine ORM mapping
     */
    #[ORM\ManyToMany(targetEntity: CampaignFunding::class, mappedBy: 'campaigns')]
    protected Collection $campaignFundings;

    /**
     * @var string  ISO 4217 code for the currency in which donations can be accepted and matching's organised.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected ?string $currencyCode;

    /**
     * Status as sent from SF API. Not currently used in matchbot but here for ad-hoc DB queries and
     * possible future use.
     *
     * Consider converting to enum or value object before using in any logic.
     *
     * Default null because campaigns not recently updated in matchbot have not pulled this field from SF.
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true, options: ['default' => null])]
    private ?string $status = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $startDate;

    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $endDate;

    /**
     * @var bool    Whether the Campaign has any match funds
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $isMatched;

    /**
     * Dictates whether campaign is/will be ready to accept donations. Currently calculated in SF Apex code
     * based on status. A campaign may be ready but not yet open, in which case it will not accept donations right now.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ready;

    #[ORM\Column()]
    private bool $isRegularGiving;

    /**
     * Date at which we want to stop collecting payments for this regular giving campaign. Must be null if
     * this is not regular giving, will also be null if this is regular giving and we plan to continue collecting
     * donations indefinitely.
     */
    #[ORM\Column(nullable: true)]
    protected ?\DateTimeImmutable $regularGivingCollectionEnd;

    /**
     * @param \DateTimeImmutable|null $regularGivingCollectionEnd
     * @param Salesforce18Id<Campaign> $sfId
     * @param bool $isRegularGiving
     */
    public function __construct(
        Salesforce18Id $sfId,
        Charity $charity,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        bool $isMatched,
        bool $ready,
        ?string $status,
        string $name,
        string $currencyCode,
        bool $isRegularGiving,
        ?\DateTimeImmutable $regularGivingCollectionEnd,
    ) {
        $this->createdNow();
        $this->campaignFundings = new ArrayCollection();
        $this->charity = $charity;
        $this->salesforceId = $sfId->value;

        $this->updateFromSfPull(
            currencyCode: $currencyCode,
            status: $status,
            endDate: $endDate,
            isMatched: $isMatched,
            name: $name,
            startDate: $startDate,
            ready: $ready,
            isRegularGiving: $isRegularGiving,
            regularGivingCollectionEnd: $regularGivingCollectionEnd,
        );
    }

    /**
     * Implemented only so this can be cast to string if required for logging etc - not for use in any business process.
     */
    public function __toString(): string
    {
        return "Campaign ID #{$this->id}, SFId: {$this->salesforceId}";
    }

    /**
     * @deprecated
     */
    #[\Override]
    public function setSalesforceId(string $salesforceId): void
    {
        throw new \Exception("Campaign sf ID is set at creation time, doesn't need to be changed later");
    }

    /**
     * @return bool does this campaign accept one-off giving, i.e. non-regular giving.
     * @see self::isRegularGiving()
     */
    public function isOneOffGiving(): bool
    {
        return ! $this->isRegularGiving;
    }

    /**
     * @return bool does this campaign accept regular giving, i.e. can it have regular giving mandates.
     */
    public function isRegularGiving(): bool
    {
        return $this->isRegularGiving;
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
            throw new \Exception(
                "Error on attempt to persist campaign #{$this->id}, sfID {$this->salesforceId}: \n{$e}"
            );
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

    public function setStartDate(DateTimeInterface $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function setEndDate(DateTimeInterface $endDate): void
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

    /**
     * Is the campaign open to accept donations at the given time?
     */
    public function isOpen(\DateTimeImmutable $at): bool
    {
        return $this->ready && $this->startDate <= $at && $this->endDate > $at;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getEndDate(): DateTimeInterface
    {
        return $this->endDate;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    final public function updateFromSfPull(
        string $currencyCode,
        ?string $status,
        \DateTimeInterface $endDate,
        bool $isMatched,
        string $name,
        \DateTimeInterface $startDate,
        bool $ready,
        bool $isRegularGiving,
        ?\DateTimeImmutable $regularGivingCollectionEnd,
    ): void {
        Assertion::lessOrEqualThan(
            $startDate,
            $endDate,
            "Campaign may not end before it starts {$this->salesforceId}"
        );

        Assertion::eq($currencyCode, 'GBP', 'Only GBP currency supported at present');
        Assertion::nullOrRegex($status, "/^[A-Za-z]{2,30}$/");
        Assertion::betweenLength($name, 2, 255);

        if (! $isRegularGiving) {
            Assertion::null(
                $regularGivingCollectionEnd,
                "Can't have a regular giving collection end date for non-regular campaign {$this->salesforceId}"
            );
        }

        $this->currencyCode = $currencyCode;
        $this->endDate = $endDate;
        $this->isMatched = $isMatched;
        $this->name = $name;
        $this->startDate = $startDate;
        $this->ready = $ready;
        $this->status = $status;
        $this->isRegularGiving = $isRegularGiving;
        $this->regularGivingCollectionEnd = $regularGivingCollectionEnd;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    #[\Override]
    public function getSalesforceId(): string
    {
        // salesforce ID is set in Campaign constructor, so should never be null.
        Assertion::string($this->salesforceId);

        return $this->salesforceId;
    }

    public function regularGivingCollectionIsEndedAt(\DateTimeImmutable $date): bool
    {
        return $this->regularGivingCollectionEnd !== null && $this->regularGivingCollectionEnd <= $date;
    }

    public function getRegularGivingCollectionEnd(): ?\DateTimeImmutable
    {
        return $this->regularGivingCollectionEnd;
    }
}
