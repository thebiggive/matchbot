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

    final public function getCreatedDateImmutable(): \DateTimeImmutable
    {
        // PHPStan issue suppressed - this would cause a crash if any users of this trait don't call
        // self::createdNow at construction.
        return \DateTimeImmutable::createFromInterface($this->createdAt); // @phpstan-ignore property.uninitialized
    }

    /**
     * @return DateTime
     */
    public function getUpdatedDate(): DateTime
    {
        return $this->updatedAt;
    }
}
