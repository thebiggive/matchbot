<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Laminas\Diactoros\Uri;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;
use Psr\Http\Message\UriInterface;

#[ORM\Table]
#[ORM\Entity(repositoryClass: CharityRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ["salesforceId"])]
class Charity extends SalesforceReadProxy
{
    use TimestampsTrait;

    public const string GIFT_AID_APPROVED_STATUS = 'Onboarded & Approved';

    private const array GIFT_AID_ONBOARDED_STATUSES = [
        'Onboarded',
        'Onboarded & Data Sent to HMRC',
        self::GIFT_AID_APPROVED_STATUS,
        // We'll always aim to fix data problems with HMRC, so should still plan to claim.
        'Onboarded but HMRC Rejected',
    ];

    private const array POSSIBLE_GIFT_AID_STATUSES = [
        ...self::GIFT_AID_ONBOARDED_STATUSES,
        'Invited to Onboard',
        'Withdrawn',
    ];

    /**
     * HMRC-permitted regulator codes and names
     */
    public const array REGULATORS = [
        'CCEW' => 'Charity Commission for England and Wales',
        'OSCR' => 'Office of the Scottish Charity Regulator',
        'CCNI' => 'Charity Commission for Northern Ireland',
    ];

    public const int MAX_REGULATOR_NUMBER_LENGTH = 10;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected string $name;

    /**
     * Full data about this charity as received from Salesforce. Not for use as-is in Matchbot domain logic but
     * may be used in ad-hoc queries, migrations, and perhaps for outputting to FE to provide compatibility with the SF
     * API.
     * @var array<string, mixed>
     */
    #[ORM\Column(type: "json", nullable: false)]
    private array $salesforceData = [];

    /**
     * URI of the charity's logo, hosted as part of the Big Give website.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $logoUri = null;

    /**
     * URI of the charity's own website, for linking to.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $websiteUri = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $phoneNumber = null;

    /**
     * Not using EmailAddress as embedded because of nullability requirement.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailAddress;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    protected ?string $stripeAccountId = null;

    #[ORM\Column(length: 7, unique: true, nullable: true)]
    protected ?string $hmrcReferenceNumber = null;

    /**
     * HMRC-permitted values only. Anything else should have this null and
     * just store an "OtherReg" number in `$regulatorNumber` if applicable.
     *
     * @var key-of<self::REGULATORS> |null
     */
    #[ORM\Column(length: 4, nullable: true)]
    protected ?string $regulator = null; // @phpstan-ignore doctrine.columnType

    #[ORM\Column(length: self::MAX_REGULATOR_NUMBER_LENGTH, nullable: true)]
    protected ?string $regulatorNumber = null;

    /**
     * @var bool    Whether the charity's Gift Aid is expected to be claimed by the Big Give. This
     *              indicates we should charge a fee and plan to claim, but not that we are necessarily
     *              set up as an approved Agent yet.
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $tbgClaimingGiftAid = false;

    /**
     * @var bool    Whether the charity's Gift Aid may NOW be claimed by the Big Give according to HMRC.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $tbgApprovedToClaimGiftAid = false;

    /**
     * @param EmailAddress|null $emailAddress
     * @param array<string,mixed> $rawData - data about the charity as sent from Salesforce
     */
    public function __construct(
        string $salesforceId,
        string $charityName,
        ?string $stripeAccountId,
        ?string $hmrcReferenceNumber,
        ?string $giftAidOnboardingStatus,
        ?string $regulator,
        ?string $regulatorNumber,
        DateTime $time,
        ?EmailAddress $emailAddress,
        ?string $websiteUri = null,
        ?string $logoUri = null,
        ?string $phoneNumber = null,
        array $rawData = [],
    ) {
        $this->updatedAt = $time;
        $this->createdAt = $time;
        $this->setSalesforceId($salesforceId);

        // every charity originates as pulled from SF.
        $this->updateFromSfPull(
            charityName: $charityName,
            websiteUri: $websiteUri,
            logoUri: $logoUri,
            stripeAccountId: $stripeAccountId,
            hmrcReferenceNumber: $hmrcReferenceNumber,
            giftAidOnboardingStatus: $giftAidOnboardingStatus,
            regulator: $regulator,
            regulatorNumber: $regulatorNumber,
            rawData: $rawData,
            time: new \DateTime('now'),
            phoneNumber: $phoneNumber,
            emailAddress: $emailAddress,
        );
    }

    public function __toString(): string
    {
        return "Charity sfID ({$this->getSalesforceId()})";
    }

    #[\Override]
    public function getSalesforceId(): string
    {
        $salesforceId = parent::getSalesforceId();
        Assertion::string($salesforceId);

        return $salesforceId;
    }

    /**
     * @param string $name
     */
    final public function setName(string $name): void
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

    /** @return key-of<self::REGULATORS> | null */
    public function getRegulator(): ?string
    {
        return $this->regulator;
    }

    public function setRegulator(?string $regulator): void
    {
        if ($regulator !== null && !array_key_exists($regulator, self::REGULATORS)) {
            throw new \UnexpectedValueException(sprintf('Regulator %s not known', $regulator));
        }

        $this->regulator = $regulator;
    }

    public function getRegulatorNumber(): ?string
    {
        return $this->regulatorNumber;
    }


    /**
     * @param string|null $regulatorNumber - if set must be 10 bytes long or less.
     *                              {@see self::MAX_REGULATOR_NUMBER_LENGTH}
     * @throws AssertionFailedException if regulator number of over 10 bytes given.
     * @return void
     */
    public function setRegulatorNumber(?string $regulatorNumber): void
    {
        Assertion::nullOrBetweenLength(
            $regulatorNumber,
            0,
            self::MAX_REGULATOR_NUMBER_LENGTH,
        );

        $this->regulatorNumber = $regulatorNumber;
    }

    public function setTbgApprovedToClaimGiftAid(bool $tbgApprovedToClaimGiftAid): void
    {
        $this->tbgApprovedToClaimGiftAid = $tbgApprovedToClaimGiftAid;
    }

    public function getTbgApprovedToClaimGiftAid(): bool
    {
        return $this->tbgApprovedToClaimGiftAid;
    }

    /**
     *
     * @param EmailAddress|null $emailAddress
     * @param array<string,mixed> $rawData Data about the charity as received directly from SF.
     *
     *@throws \UnexpectedValueException if $giftAidOnboardingStatus is not listed in self::POSSIBLE_GIFT_AID_STATUSES
     */
    final public function updateFromSfPull(
        string $charityName,
        ?string $websiteUri,
        ?string $logoUri,
        ?string $stripeAccountId,
        ?string $hmrcReferenceNumber,
        ?string $giftAidOnboardingStatus,
        ?string $regulator,
        ?string $regulatorNumber,
        array $rawData,
        DateTime $time,
        ?string $phoneNumber,
        ?EmailAddress $emailAddress,
    ): void {
        $statusUnexpected = !is_null($giftAidOnboardingStatus)
            && !in_array($giftAidOnboardingStatus, self::POSSIBLE_GIFT_AID_STATUSES, true);
        if ($statusUnexpected) {
            throw new \UnexpectedValueException();
        }

        $this->setName($charityName);
        $this->setStripeAccountId($stripeAccountId);

        $tbgCanClaimGiftAid = (
            $hmrcReferenceNumber !== null && $hmrcReferenceNumber !== '' &&
            in_array($giftAidOnboardingStatus, self::GIFT_AID_ONBOARDED_STATUSES, true)
        );
        $tbgApprovedToClaimGiftAid = (
            $hmrcReferenceNumber !== null && $hmrcReferenceNumber !== '' &&
            $giftAidOnboardingStatus === self::GIFT_AID_APPROVED_STATUS
        );

        $this->setTbgClaimingGiftAid($tbgCanClaimGiftAid);
        $this->setTbgApprovedToClaimGiftAid($tbgApprovedToClaimGiftAid);

        // May be null. Should be set to its string value if provided even if the charity is now opted out for new
        // claims, because there could still be historic donations that should be claimed by TBG.
        $this->setHmrcReferenceNumber($hmrcReferenceNumber);

        $this->setRegulator($regulator);
        $this->setRegulatorNumber($regulatorNumber);

        try {
            $this->setWebsiteUri($websiteUri);
        } catch (AssertionFailedException $e) {
            // not setting an invalid URL, but the old
            // one may not be right either so best we can do is set null.
            $this->setWebsiteUri(null);
        }
        $this->setLogoUri($logoUri);
        $this->setPhoneNumber($phoneNumber);

        $this->salesforceData = $rawData;

        $this->setSalesforceLastPull($time);

        $this->emailAddress = $emailAddress?->email;
    }

    public function getStatementDescriptor(): string
    {
        $maximumLength = 22; // https://stripe.com/docs/payments/payment-intents#dynamic-statement-descriptor
        $prefix = 'Big Give ';

        return $prefix . mb_substr(
            $this->removeSpecialChars($this->getName()),
            0,
            $maximumLength - mb_strlen($prefix),
        );
    }

    // Remove special characters except spaces
    private function removeSpecialChars(string $descriptor): string
    {
        $return = preg_replace('/[^A-Za-z0-9 ]/', '', $descriptor);

        \assert($return !== null);

        return $return;
    }

    public function getRegulatorName(): ?string
    {
        if ($this->regulator == null) {
            return null;
        }

        return self::REGULATORS[$this->regulator];
    }

    public function isExempt(): bool
    {
        // This is a slightly risky assumption to make, but mailer is already making it. By moving the logic
        // to here it gets one step closer to directly storing what was entered.

        // todo - adjust \MatchBot\Domain\CampaignRepository::doUpdateFromSf to recognise the magic value 'Exempt'
        return $this->regulatorNumber === null;
    }

    /**
     * @throws AssertionFailedException if param is an invald URL.
     */
    private function setWebsiteUri(?string $websiteUri): void
    {
        $websiteUri = $this->replaceBlankWithNull($websiteUri);

        Assertion::nullOrUrl($websiteUri);
        $this->websiteUri = $websiteUri;
    }

    private function setLogoUri(?string $logoUri): void
    {
        $logoUri = $this->replaceBlankWithNull($logoUri);

        Assertion::nullOrUrl($logoUri);
        $this->logoUri = $logoUri;
    }

    private function setPhoneNumber(?string $phoneNumber): void
    {
        $phoneNumber = $this->replaceBlankWithNull($phoneNumber);
        Assertion::nullOrBetweenLength($phoneNumber, 1, 255);

        $this->phoneNumber = $phoneNumber;
    }

    /**
     * Could throw in theory InvalidArgumentException as with {@see self::getWebsiteUri()} if we have a malformed
     * logoUri stored but not reason to think our systems would allow that to happen
     */
    public function getLogoUri(): ?UriInterface
    {
        return is_null($this->logoUri) ? null : new Uri($this->logoUri);
    }

    /**
     * Can throw because we don't validate the URI on input or when saving the charity.
     *
     * @throws \Laminas\Diactoros\Exception\InvalidArgumentException
     */
    public function getWebsiteUri(): ?UriInterface
    {
        return is_null($this->websiteUri) ? null : new Uri($this->websiteUri);
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    private function replaceBlankWithNull(?string $string): ?string
    {
        if (trim($string ?? '') === '') {
            $string = null;
        }
        return $string;
    }

    public function getEmailAddress(): ?EmailAddress
    {
        if ($this->emailAddress === null) {
            return null;
        }

        return EmailAddress::of($this->emailAddress);
    }

    /** @return array<string, mixed> */
    public function getSalesforceData(): array
    {
        return $this->salesforceData;
    }
}
