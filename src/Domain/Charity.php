<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table]
#[ORM\Entity(repositoryClass: CharityRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ["salesforceId"])]
class Charity extends SalesforceReadProxy
{
    use TimestampsTrait;

    private const GIFT_AID_APPROVED_STATUS = 'Onboarded & Approved';

    private const GIFT_AID_ONBOARDED_STATUSES = [
        'Onboarded',
        'Onboarded & Data Sent to HMRC',
        self::GIFT_AID_APPROVED_STATUS,
        // We'll always aim to fix data problems with HMRC, so should still plan to claim.
        'Onboarded but HMRC Rejected',
    ];

    private const POSSIBLE_GIFT_AID_STATUSES = [
        ...self::GIFT_AID_ONBOARDED_STATUSES,
        'Invited to Onboard',
        'Withdrawn',
    ];

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: true)]
    protected ?string $stripeAccountId = null;

    /**
     * @var ?string
     */
    #[ORM\Column(type: 'string', length: 7, unique: true, nullable: true)]
    protected ?string $hmrcReferenceNumber = null;

    /**
     * HMRC-permitted values: CCEW, CCNI, OSCR. Anything else should have this null and
     * just store an "OtherReg" number in `$regulatorNumber` if applicable.
     *
     * @var ?string
     * @see Charity::$permittedRegulators
     */
    #[ORM\Column(type: 'string', length: 4, nullable: true)]
    protected ?string $regulator = null;

    /**
     * @var ?string
     */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    protected ?string $regulatorNumber = null;

    private static array $permittedRegulators = ['CCEW', 'CCNI', 'OSCR'];

    /**
     * @var bool    Whether the charity's Gift Aid is expected to be claimed by the Big Give. This
     *              indicates we should charge a fee and plan to claim, but not that we are necessarily
     *              set up as an approved Agent yet.
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $tbgClaimingGiftAid = false;

    /**
     * @psalm-suppress UnusedProperty - used in a database query in DonationRepository::findReadyToClaimGiftAid
     * @var bool    Whether the charity's Gift Aid may NOW be claimed by the Big Give according to HMRC.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $tbgApprovedToClaimGiftAid = false;

    public function __construct(
        string $salesforceId,
        string $charityName,
        ?string $stripeAccountId,
        ?string $hmrcReferenceNumber,
        ?string $giftAidOnboardingStatus,
        ?string $regulator,
        ?string $regulatorNumber,
        DateTime $time,
    ) {
        $this->updatedAt = $time;
        $this->createdAt = $time;
        $this->setSalesforceId($salesforceId);

        // every charity originates as pulled from SF.
        $this->updateFromSfPull(
            charityName: $charityName,
            stripeAccountId: $stripeAccountId,
            hmrcReferenceNumber: $hmrcReferenceNumber,
            giftAidOnboardingStatus: $giftAidOnboardingStatus,
            regulator: $regulator,
            regulatorNumber: $regulatorNumber,
            time: new \DateTime('now'),
        );
    }

    public function __toString(): string
    {
        return "Charity sfID ($this->salesforceId}";
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    /**
     * @param string|null $stripeAccountId
     */
    public function setStripeAccountId(?string $stripeAccountId): void
    {
        $this->stripeAccountId = $stripeAccountId;
    }

    public function isTbgClaimingGiftAid(): bool
    {
        return $this->tbgClaimingGiftAid;
    }

    public function setTbgClaimingGiftAid(bool $tbgClaimingGiftAid): void
    {
        $this->tbgClaimingGiftAid = $tbgClaimingGiftAid;
    }

    /**
     * @return string|null
     */
    public function getHmrcReferenceNumber(): ?string
    {
        return $this->hmrcReferenceNumber;
    }

    /**
     * @param string|null $hmrcReferenceNumber
     */
    public function setHmrcReferenceNumber(?string $hmrcReferenceNumber): void
    {
        $this->hmrcReferenceNumber = $hmrcReferenceNumber;
    }

    public function getRegulator(): ?string
    {
        return $this->regulator;
    }

    public function setRegulator(?string $regulator): void
    {
        if ($regulator !== null && !in_array($regulator, static::$permittedRegulators, true)) {
            throw new \UnexpectedValueException(sprintf('Regulator %s not known', $regulator));
        }

        $this->regulator = $regulator;
    }

    public function getRegulatorNumber(): ?string
    {
        return $this->regulatorNumber;
    }

    public function setRegulatorNumber(?string $regulatorNumber): void
    {
        $this->regulatorNumber = $regulatorNumber;
    }

    public function setTbgApprovedToClaimGiftAid(bool $tbgApprovedToClaimGiftAid): void
    {
        $this->tbgApprovedToClaimGiftAid = $tbgApprovedToClaimGiftAid;
    }

    /**
     * @throws \UnexpectedValueException if $giftAidOnboardingStatus is not listed in self::POSSIBLE_GIFT_AID_STATUSES
     */
    public function updateFromSfPull(
        string $charityName,
        ?string $stripeAccountId,
        ?string $hmrcReferenceNumber,
        ?string $giftAidOnboardingStatus,
        ?string $regulator,
        ?string $regulatorNumber,
        DateTime $time,
    ): void {
        $statusUnexpected = !is_null($giftAidOnboardingStatus)
            && !in_array($giftAidOnboardingStatus, self::POSSIBLE_GIFT_AID_STATUSES, true);
        if ($statusUnexpected) {
            throw new \UnexpectedValueException();
        }

        $this->setName($charityName);
        $this->setStripeAccountId($stripeAccountId);

        $tbgCanClaimGiftAid = (
            !empty($hmrcReferenceNumber) &&
            in_array($giftAidOnboardingStatus, self::GIFT_AID_ONBOARDED_STATUSES, true)
        );
        $tbgApprovedToClaimGiftAid = (
            !empty($hmrcReferenceNumber) &&
            $giftAidOnboardingStatus === self::GIFT_AID_APPROVED_STATUS
        );

        $this->setTbgClaimingGiftAid($tbgCanClaimGiftAid);
        $this->setTbgApprovedToClaimGiftAid($tbgApprovedToClaimGiftAid);

        // May be null. Should be set to its string value if provided even if the charity is now opted out for new
        // claims, because there could still be historic donations that should be claimed by TBG.
        $this->setHmrcReferenceNumber($hmrcReferenceNumber);

        $this->setRegulator($regulator);
        $this->setRegulatorNumber($regulatorNumber);

        $this->setSalesforceLastPull($time);
    }
}
