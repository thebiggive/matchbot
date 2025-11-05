<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait to define timestamp fields and set them when appropriate. For this to work the models *must* be
 * annotated with `#[ORM\HasLifecycleCallbacks]` at class level.
 */
trait TimestampsTrait
{
    /**
     * @var DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected DateTime $createdAt;

    /**
     * @var DateTime
     */
    #[ORM\Column(type: 'datetime')]
    protected DateTime $updatedAt;

    #[ORM\PrePersist]
    final public function createdNow(): void
    {
        $this->createdAt = new \DateTime('now');
        $this->updatedAt = new \DateTime('now');
    }

    #[ORM\PreUpdate]
    public function updatedNow(): void
    {
        $this->updatedAt = new \DateTime('now');
    }

    /**
     * @return DateTime
     */
    public function getCreatedDate(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return DateTime
     */
    public function getUpdatedDate(): DateTime
    {
        return $this->updatedAt;
    }
}
