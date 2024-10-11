<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a commitment of match funds, i.e. a Champion Fund or Pledge. Because a Fund (most
 * typically a Champion Fund) can be split up and allocated to multiple Campaigns, the Fund in
 * MatchBot doesn't contain an allocated amount and is mostly a container for metadata to help understand
 * where any linked {@see CampaignFunding}s' money comes from.
 *
 * Concrete subclasses {@see ChampionFund} & {@see Pledge} are instantiated using Doctrine's
 * single table inheritance. The discriminator column is 'fundType' and the API field which determines
 * it originally, in {@see FundRepository::getNewFund()}, is 'type'.
 */
#[ORM\Table]
#[ORM\Entity(repositoryClass: FundRepository::class)]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'fundType', type: 'string')]
#[ORM\DiscriminatorMap(['championFund' => 'ChampionFund', 'pledge' => 'Pledge', 'unknownFund' => 'Fund'])]
#[ORM\HasLifecycleCallbacks]
abstract class Fund extends SalesforceReadProxy
{
    use TimestampsTrait;

    /** @var 'championFund'|'pledge'|'unknownFund' */
    public const string DISCRIMINATOR_VALUE = 'unknownFund';

    /**
     * @var string  ISO 4217 code for the currency used with this fund, and in which FundingWithdrawals are denominated.
     */
    #[ORM\Column(type: 'string', length: 3)]
    protected string $currencyCode;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    final public function __construct(string $currencyCode, string $name)
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->currencyCode = $currencyCode;
        $this->name = $name;
    }

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

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }
}
