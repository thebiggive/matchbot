<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

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
    public const DISCRIMINATOR_VALUE = 'unknownFund';

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
