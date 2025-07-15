<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\UpdateCampaignDonationStats;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatisticsRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MatchFundsService;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\LockFactory;

class CampaignStatsAfterDonationCollectedTest extends IntegrationTest
{
    private Campaign $campaign;
    private const string PSP_CUSTOMER_ID = 'cus_inttest_1';
    private const string FUND_TOTAL_AMOUNT = '500.00';
    private const string DONATION_AMOUNT = '4.00';
    private const string RAISED_AFTER_DONATION = '8.00';

    #[\Override]
    public function setUp(): void
    {
        $this->campaign = $this->createCampaign();
        parent::setUp();
    }

    public function testStatsAreZeroWhenNoDonations(): void
    {
        $repo = $this->getContainer()->get(CampaignStatisticsRepository::class);

        $stats = $repo->getStatistics($this->campaign);
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getAmountRaised());
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getDonationSum());
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getMatchFundsTotal());
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getMatchFundsUsed());
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getMatchFundsRemaining());
        $this->assertEquals(Money::zero($this->campaign->getCurrency()), $stats->getDistanceToTarget());
    }

    public function testStatsMatchOneCollectedDonation(): void
    {
        // arrange
        $repo = $this->getContainer()->get(CampaignStatisticsRepository::class);
        $this->addCollectedMatchedDonation($this->campaign);

        $this->runStatsUpdateCommand();

        // act
        $stats = $repo->getStatistics($this->campaign);

        // assert
        $moneyDonated = Money::fromNumericStringGBP(self::DONATION_AMOUNT);
        $totalMatchFunds = Money::fromNumericStringGBP(self::FUND_TOTAL_AMOUNT);
        $expectedRemaining = $totalMatchFunds->minus(Money::fromNumericStringGBP(self::DONATION_AMOUNT));

        $this->assertEquals($moneyDonated, $stats->getDonationSum());
        $this->assertEquals($moneyDonated, $stats->getMatchFundsUsed());
        $this->assertEquals($totalMatchFunds, $stats->getMatchFundsTotal());
        $this->assertEquals($expectedRemaining, $stats->getMatchFundsRemaining());
        $this->assertEquals(
            Money::fromNumericStringGBP(self::RAISED_AFTER_DONATION),
            $stats->getAmountRaised(),
        );
        // Using major units in the test since the 2nd operand can be negative and Money doesn't allow that. Runtime code
        // uses Money less-than comparison check instead and returns 0 if it would be negative, hence `max(0, ...)`.
        $leftToTargetMajorUnit = max(
            0,
            (int) $this->campaign->getTotalFundraisingTarget()->toMajorUnitFloat() - (int) self::RAISED_AFTER_DONATION,
        );
        $this->assertEquals($leftToTargetMajorUnit, $stats->getDistanceToTarget()->toMajorUnitFloat());
    }

    private function addCollectedMatchedDonation(Campaign $campaign): void
    {
        $donation = Donation::fromApiModel(
            donationData: new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: self::DONATION_AMOUNT,
                projectId: 'projectID123456789',
                psp: 'stripe',
                pspMethodType: PaymentMethodType::Card,
                pspCustomerId: self::PSP_CUSTOMER_ID,
                emailAddress: 'email' . random_int(1000, 99999) . '@example.com',
            ),
            campaign: $campaign,
            donorId: PersonId::nil()
        );

        $donation->collectFromStripeCharge(
            chargeId: 'charge_id',
            totalPaidFractional: 300,
            transferId: 'transfer_id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: (new \DateTimeImmutable())->sub(new \DateInterval('PT1M'))->getTimestamp()
        );

        $pledge = new Fund(currencyCode: 'GBP', name: '', salesforceId: null, fundType: FundType::Pledge);
        $campaignFunding = new CampaignFunding(
            fund: $pledge,
            amount: self::FUND_TOTAL_AMOUNT,
            amountAvailable: self::FUND_TOTAL_AMOUNT,
        );
        $campaignFunding->addCampaign($campaign);

        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);
        $donation->addFundingWithdrawal($fundingWithdrawal);
        $fundingWithdrawal->setAmount(self::DONATION_AMOUNT); // Fully matched donation.
        $fundingWithdrawal->setDonation($donation);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($pledge);
        $em->persist($campaignFunding);
        $em->persist($fundingWithdrawal);
        $em->persist($donation->getCampaign());
        $em->persist($donation->getCampaign()->getCharity());
        $em->persist($donation);

        $em->flush();
    }

    private function runStatsUpdateCommand(): void
    {
        $output = new BufferedOutput();
        $lockFactory = new LockFactory(new AlwaysAvailableLockStore());
        $application = $this->buildMinimalApp($lockFactory);
        $command = new UpdateCampaignDonationStats(
            $this->getContainer()->get(CampaignRepository::class),
            $this->getContainer()->get(EntityManagerInterface::class),
            $this->getContainer()->get(MatchFundsService::class),
        );
        $command->setApplication($application);
        $command->setLockFactory($lockFactory);

        $command->run(new ArrayInput([]), $output);

        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:update-campaign-donation-stats starting!',
            "Prepared statistics for campaign ID {$this->campaign->getId()}, SF ID campaignid12345678",
            'Updated statistics for 1 of 1 campaigns with recent donations',
            'Updated statistics for 0 of 0 campaigns with no recent stats',
            'matchbot:update-campaign-donation-stats complete!',
            '',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }

    private function buildMinimalApp(LockFactory $lockFactory): Application
    {
        $app = new Application();

        $command = $this->getService(UpdateCampaignDonationStats::class);
        $command->setLockFactory($lockFactory);
        $app->add($command);

        return $app;
    }
}
