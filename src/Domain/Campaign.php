<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table
 *
 * Represents any Campaign type in Salesforce which can receive donations. Note that this does NOT include Master
 * record type(s). The only way Salesforce type impacts this model is in setting `$isMatched` appropriately.
 */
class Campaign extends SalesforceProxy
{
    use TimestampsTrait;

    /**
     * @ORM\ManyToOne(targetEntity="Charity")
     * @var Charity
     */
    protected $charity;

    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $name;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $startDate;

    /**
     * @ORM\Column(type="datetime")
     * @var DateTime
     */
    protected $endDate;

    /**
     * @ORM\Column(type="boolean")
     * @var bool    Whether the Campaign has any match funds
     */
    protected $isMatched;

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
}
