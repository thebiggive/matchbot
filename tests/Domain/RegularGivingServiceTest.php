<?php

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingService;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**

 */
class RegularGivingServiceTest extends TestCase
{
    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepositoryProphecy;

    /** @var ObjectProphecy<DonorAccountRepository> */
    private ObjectProphecy $donorAccountRepositoryProphecy;

    /** @var ObjectProphecy<CampaignRepository> */
    private ObjectProphecy $campaignRepositoryProphecy;
    private DonorAccount $donorAccount;
    private PersonId $personId;

    /** @var Salesforce18Id<Campaign> */
    private Salesforce18Id $campaignId;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
        $this->campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);

        $this->donorAccount = new DonorAccount(
            null,
            EmailAddress::of('email@example.com'),
            DonorName::of('First', 'Last'),
            StripeCustomerId::of('cus_x')
        );
        $this->donorAccount->setBillingCountryCode('GB');
        $this->donorAccount->setBillingPostcode('SW11AA');

        $this->campaignId = Salesforce18Id::ofCampaign('campaignId12345678');
        $this->personId = PersonId::of('d38667b2-69db-11ef-8885-3f5bcdfd1960');

        $this->donorAccountRepositoryProphecy->findByPersonId($this->personId)
            ->willReturn($this->donorAccount);
        $this->campaignRepositoryProphecy->findOneBySalesforceId($this->campaignId)
            ->willReturn(TestCase::someCampaign());
    }

    public function testCannotMakeAMandateForNonRegularGivingCampaign(): void
    {
        $regularGivingService = $this->makeSUT(new \DateTimeImmutable('2024-11-29T05:59:59 BST'));
        $campaign = TestCase::someCampaign();

        $this->expectException(WrongCampaignType::class);

        // By default campaign is not a regular giving campaign
        $regularGivingService->setupNewMandate(
            PersonId::of(Uuid::uuid4()->toString()),
            Money::fromPoundsGBP(50),
            $campaign,
            giftAid: false,
            dayOfMonth: DayOfMonth::of(12)
        );
    }
    public function testMakingNextDonationForMandate(): void
    {
        $sut = $this->makeSut(simulatedNow: new \DateTimeImmutable('2024-10-02T06:00:00+0100'));
        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        $donation = $sut->makeNextDonationForMandate($mandate);

        $this->assertNotNull($donation);
        $this->assertEquals(DonationSequenceNumber::of(2), $donation->getMandateSequenceNumber());
        $this->assertSame(DonationStatus::PreAuthorized, $donation->getDonationStatus());
        $this->assertEquals(
            new \DateTimeImmutable('2024-10-02T06:00:00.000000+0100'),
            $donation->getPreAuthorizationDate()
        );
    }

    /**
     * There's generally no need to create donations authorized only for payment in the future. We can wait
     * until the authorization time is past and then create the donation and pay it a few minutes or hours later
     * than the first opportunity.
     *
     * Creating donations authorized early is problematic because if we don't limit the timespan we'd have too many
     * inchoate donations in the db if we run the script many times, and we want to create them as late as possible
     * so we can use up to date info from the mandate and the donors account. Most importantly info about whether the
     * mandate has been cancelled but also contact details.
     */
    public function testDoesNotMakeDonationAuthorizedForFuturePayment(): void
    {
        $sut = $this->makeSut(simulatedNow: new \DateTimeImmutable('2024-10-02T05:59:59 BST'));

        $mandate = $this->getMandate(2, '2024-09-03T06:00:00 BST', 1);

        // next donation will be number 2. Mandate is activated on 2024-09-03 and dayOfMonth is 2 so donation 2 should
        //be authed for 2024-10-02 . So we should not create this donation if the current date is <
        // '2024-10-02T06:00:00.000000 BST'
        $donation = $sut->makeNextDonationForMandate($mandate);

        $this->assertNull($donation);
    }

    public function makeSut(\DateTimeImmutable $simulatedNow): RegularGivingService
    {
        return new RegularGivingService(
            now: $simulatedNow,
            donationRepository: $this->donationRepositoryProphecy->reveal(),
            donorAccountRepository: $this->donorAccountRepositoryProphecy->reveal(),
            campaignRepository: $this->campaignRepositoryProphecy->reveal(),
            entityManager: $this->createStub(EntityManagerInterface::class),
            donationService: $this->createStub(DonationService::class),
            log: $this->createStub(LoggerInterface::class),
            regularGivingMandateRepository: $this->createStub(RegularGivingMandateRepository::class),
        );
    }

    public function getMandate(int $dayOfMonth, string $activationDate, int $maxSequenceNumber): RegularGivingMandate
    {
        $mandateId = 53;
        $mandate = new RegularGivingMandate(
            $this->personId,
            Money::fromPoundsGBP(1),
            $this->campaignId,
            Salesforce18Id::ofCharity('charityId123456789'),
            false,
            DayOfMonth::of($dayOfMonth),
        );
        $mandate->setId($mandateId);

        $mandate->activate(new \DateTimeImmutable($activationDate));

        $this->donationRepositoryProphecy->maxSequenceNumberForMandate($mandateId)
            ->willReturn(DonationSequenceNumber::of($maxSequenceNumber));
        return $mandate;
    }
}
