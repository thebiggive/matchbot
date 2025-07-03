<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\UpdateCampaignDonationStats;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatisticsRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use MatchBot\Tests\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\LockFactory;

class CampaignStatsAfterDonationCollectedTest extends IntegrationTest
{
    private Campaign $campaign;
    private const string PSP_CUSTOMER_ID = 'cus_inttest_1';
    private const string DONATION_AMOUNT = '4.00';
    private const string RAISED_AFTER_DONATION = '8.00';

    #[\Override]
    public function setUp(): void
    {
        $this->campaign = $this->makeCampaign();
        parent::setUp();
    }

    public function testStatsAreZeroWhenNoDonations(): void
    {
        $repo = $this->getContainer()->get(CampaignStatisticsRepository::class);

        $stats = $repo->getStatistics($this->campaign);
        $this->assertEquals(Money::zero(), $stats->getAmountRaised());
        $this->assertEquals(Money::zero(), $stats->getMatchFundsUsed());
    }

    public function testStatsMatchOneCollectedDonation(): void
    {
        $repo = $this->getContainer()->get(CampaignStatisticsRepository::class);
        $this->addCollectedMatchedDonation($this->campaign);

        $this->runStatsUpdateCommand();

        $stats = $repo->getStatistics($this->campaign);
        $this->assertEquals(Money::fromNumericStringGBP(self::RAISED_AFTER_DONATION), $stats->getAmountRaised());
        $this->assertEquals(Money::fromNumericStringGBP(self::DONATION_AMOUNT), $stats->getMatchFundsUsed());
    }

    /**
     * Copied from DonationRepositoryTest for now.
     * @todo maybe put somewhere shared?
     */
    private function makeCampaign(?Charity $charity = null): Campaign
    {
        return new Campaign(
            Salesforce18Id::ofCampaign('campaignId12345678'),
            metaCampaignSlug: null,
            charity: $charity ?? TestCase::someCharity(),
            startDate: new \DateTimeImmutable('now'),
            endDate: new \DateTimeImmutable('now'),
            isMatched: true,
            ready: true,
            status: 'Active',
            name: 'Campaign Name',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
            isRegularGiving: false,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundraisingTarget: Money::zero(),
        );
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
            amount: '500.0',
            amountAvailable: '500.0',
        );
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
            $this->getContainer()->get(CampaignStatisticsRepository::class),
            $this->getContainer()->get(EntityManagerInterface::class),
        );
        $command->setApplication($application);
        $command->setLockFactory($lockFactory);

        $command->run(new ArrayInput([]), $output);

        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:update-campaign-donation-stats starting!',
            "Prepared statistics for campaign ID {$this->campaign->getId()}, SF ID campaignid12345678",
            'Updated statistics for 1 campaigns with recent donations',
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
