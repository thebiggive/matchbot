<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * @psalm-suppress UnusedProperty - properties being brought into use now
 */
#[ORM\Table]
#[ORM\Index(name: 'uuid', columns: ['uuid'])]
#[ORM\Entity(
    repositoryClass: null // we construct our own repository
)]
#[ORM\HasLifecycleCallbacks]
class RegularGivingMandate extends SalesforceWriteProxy
{
    #[ORM\Column(unique: true, type: 'uuid')]
    public readonly UuidInterface $uuid;

    #[ORM\Embedded(columnPrefix: 'person')]
    public PersonId $donorId;

    #[ORM\Embedded(columnPrefix: '')]
    public readonly Money $amount;

    #[ORM\Column()]
    private readonly string $campaignId;

    #[ORM\Column()]
    private readonly string $charityId;

    #[ORM\Column()]
    private readonly bool $giftAid;

    // todo - add more properties - donation schedule, status etc. Maybe wait until implementing the FE display of them
    // rather than adding preemptively.
    public function __construct(
        PersonId $donorId,
        Money $amount,
        Salesforce18Id $campaignId,
        Salesforce18Id $charityId,
        bool $giftAid,
    ) {
        $this->uuid = Uuid::uuid4();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->amount = $amount;
        $this->campaignId = $campaignId->value;
        $this->charityId = $charityId->value;
        $this->giftAid = $giftAid;
        $this->donorId = $donorId;
    }

    public function toFrontEndApiModel(): array
    {
        return [
            'id' => $this->uuid->toString(),
            'donorId' => $this->donorId->id,
            'amount' => $this->amount,
            'campaignId' => $this->campaignId,
            'charityId' => $this->charityId,
            'schedule' => [
                'type' => 'monthly',
                'dayOfMonth' => 31,
                'activeFrom' => (new \DateTimeImmutable('2024-08-06'))->format(\DateTimeInterface::ATOM),
            ],
            'charityName' => 'Some Charity',
            'giftAid' => $this->giftAid,
            'status' => 'active',
            'tipAmount' => Money::fromPoundsGBP(1),
        ];
    }
}
