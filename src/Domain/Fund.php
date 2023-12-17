<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="FundRepository")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="fundType", type="string")
 * @ORM\DiscriminatorMap({"championFund" = "ChampionFund", "pledge" = "Pledge", "unknownFund" = "Fund"})
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 */
abstract class Fund extends SalesforceReadProxy
{
    use TimestampsTrait;

    public const DISCRIMINATOR_VALUE = 'unknownFund';

    /**
     * @ORM\Column(type="decimal", precision=18, scale=2)
     * @var string Always use bcmath methods as in repository helpers to avoid doing float maths with decimals!
     * @see Fund::$currencyCode
     */
    protected string $amount;

    /**
     * @ORM\Column(type="string", length=3)
     * @var string  ISO 4217 code for the currency of amount, and in which FundingWithdrawals are denominated.
     */
    protected string $currencyCode;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected string $name;

    /**
     * @param string $amount
     */
    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
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

    /**
     * @return string
     */
    public function getAmount(): string
    {
        return $this->amount;
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
