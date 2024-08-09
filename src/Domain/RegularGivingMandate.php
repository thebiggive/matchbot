<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use MatchBot\Application\Assertion;
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
    public readonly string $charityId;

    #[ORM\Column()]
    private readonly bool $giftAid;

    #[ORM\Embedded(columnPrefix: false)]
    private DayOfMonth $dayOfMonth;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activeFrom = null;

    #[ORM\Column(type: 'string', enumType: MandateStatus::class)]
    private MandateStatus $status = MandateStatus::Pending;

    /**
     * @param Salesforce18Id<Campaign> $campaignId
     * @param Salesforce18Id<Charity> $charityId
     */
    public function __construct(
        PersonId $donorId,
        Money $amount,
        Salesforce18Id $campaignId,
        Salesforce18Id $charityId,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
    ) {
        $this->uuid = Uuid::uuid4();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->amount = $amount;
        $this->campaignId = $campaignId->value;
        $this->charityId = $charityId->value;
        $this->giftAid = $giftAid;
        $this->donorId = $donorId;
        $this->dayOfMonth = $dayOfMonth;
    }

    /**
     * Allows us to take payments according to this agreement from now on.
     *
     * Precondition: Must be in Pending status
     */
    public function activate(\DateTimeImmutable $activationDate): void
    {
        Assertion::eq($this->status, MandateStatus::Pending);
        $this->status = MandateStatus::Active;
        $this->activeFrom = $activationDate;
    }

    public function toFrontEndApiModel(Charity $charity): array
    {
        Assertion::same($charity->salesforceId, $this->charityId);

        return [
            'id' => $this->uuid->toString(),
            'donorId' => $this->donorId->id,
            'amount' => $this->amount,
            'campaignId' => $this->campaignId,
            'charityId' => $this->charityId,
            'schedule' => [
                'type' => 'monthly',
                'dayOfMonth' => $this->dayOfMonth->value,
                'activeFrom' => $this->activeFrom?->format(\DateTimeInterface::ATOM),
            ],
            'charityName' => $charity->getName(),
            'giftAid' => $this->giftAid,
            'status' => $this->status->apiName(),
            'tipAmount' => Money::fromPoundsGBP(1),
        ];
    }
}
