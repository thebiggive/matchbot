<?php

namespace Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\MandateService;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;

/**

 */
class MandateServiceTest extends TestCase
{
    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepositoryProphecy;

    /** @var ObjectProphecy<DonorAccountRepository> */
    private ObjectProphecy $donorAccountRepositoryProphecy;

    /** @var ObjectProphecy<CampaignRepository> */
    private ObjectProphecy $campaignRepositoryProphecy;
    private MandateService $sut;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
        $this->campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $this->sut = new MandateService(
            $this->donationRepositoryProphecy->reveal(),
            $this->donorAccountRepositoryProphecy->reveal(),
            $this->campaignRepositoryProphecy->reveal(),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(DonationService::class),
        );
    }
    public function testMakingNextDonationForMandate(): void
    {
        // arrange
        $personId = PersonId::of('d38667b2-69db-11ef-8885-3f5bcdfd1960');
        $campaignId = Salesforce18Id::ofCampaign('campaignId12345678');
        $donorAccount = new DonorAccount(
            null,
            EmailAddress::of('email@example.com'),
            DonorName::of('First', 'Last'),
            StripeCustomerId::of('cus_x')
        );
        $donorAccount->setBillingCountryCode('GB');
        $donorAccount->setBillingPostcode('SW11AA');

        $mandateId = 53;

        $mandate = new RegularGivingMandate(
            $personId,
            Money::fromPoundsGBP(1),
            $campaignId,
            Salesforce18Id::ofCharity('charityId123456789'),
            false,
            DayOfMonth::of(2),
        );
        $mandate->setId($mandateId);
        $mandate->activate(new \DateTimeImmutable('2024-09-03T06:00:00.000000 BST'));

        $this->donationRepositoryProphecy->maxSequenceNumberForMandate($mandateId)
            ->willReturn(DonationSequenceNumber::of(1));
        $this->donorAccountRepositoryProphecy->findByPersonId($personId)
            ->willReturn($donorAccount);
        $this->campaignRepositoryProphecy->findOneBySalesforceId($campaignId)
            ->willReturn(TestCase::someCampaign());

        // act
        $donation = $this->sut->makeNextDonationForMandate($mandate);

        // assert
        $this->assertEquals(DonationSequenceNumber::of(2), $donation->getMandateSequenceNumber());
        $this->assertSame(DonationStatus::PreAuthorized, $donation->getDonationStatus());
        $this->assertEquals(
            new \DateTimeImmutable('2024-10-02T06:00:00.000000+0100'),
            $donation->getPreAuthorizationDate()
        );
    }
}
