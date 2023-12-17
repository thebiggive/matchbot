<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\LockFactory;

class RedistributeMatchingCommandTest extends IntegrationTest
{
    private CampaignFundingRepository $campaignFundingRepository;
    private Donation $donation;

    public function setUp(): void
    {
        parent::setUp();

        $this->setupFakeDonationClient();
        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);

        ['campaignId' => $campaignId] = $this->addCampaignAndCharityToDB(campaignSfId: $this->randomString());

        $campaign = $this->getService(\MatchBot\Domain\CampaignRepository::class)->find($campaignId);
        Assertion::notNull($campaign);

        $this->addFunding(
            campaignId: $campaignId,
            amountInPounds: 250,
            allocationOrder: 100,
            fundType: Pledge::DISCRIMINATOR_VALUE,
        );
        ['campaignFundingId' => $championFundCampaignFundingId] = $this->addFunding(
            campaignId: $campaignId,
            amountInPounds: 250,
            allocationOrder: 200,
            fundType: ChampionFund::DISCRIMINATOR_VALUE,
        );
        $championFundCampaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($championFundCampaignFundingId);
        Assertion::notNull($championFundCampaignFunding);

        $this->donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '250',
            projectId: 'any project',
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        ), $campaign);
        $this->donation->setTransactionId('pi_' . $this->randomString());
        $this->donation->setDonationStatus(DonationStatus::Collected);
        $this->donation->setCollectedAt(new \DateTimeImmutable('now'));

        $championFundWithdrawal = new FundingWithdrawal($championFundCampaignFunding);
        $championFundWithdrawal->setAmount('250.00');
        $this->donation->addFundingWithdrawal($championFundWithdrawal);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($championFundWithdrawal);
        $em->persist($this->donation);
        $em->flush();
    }

    public function testCommandRedistributesMatchingToUsePledges(): void
    {
        // arrange
        $hook = $this->donation->toHookModel();
        $this->assertSame('250.00', $this->donation->getFundingWithdrawalTotal());
        $this->assertSame(250.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(0.00, $hook['amountMatchedByPledges']);
        $output = new BufferedOutput();

        // act
        $command = new RedistributeMatchFunds(
            $this->campaignFundingRepository,
            $this->getService(\MatchBot\Domain\DonationRepository::class),
            $this->getService(LoggerInterface::class)
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->run(new ArrayInput([]), $output);

        // assert
        $this->assertSame('250.00', $this->donation->getFundingWithdrawalTotal());
        $hook = $this->donation->toHookModel();
        $this->assertSame(0.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(250.00, $hook['amountMatchedByPledges']);

        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:redistribute-match-funds starting!',
            'Checked 1 donations and redistributed matching for 1',
            'matchbot:redistribute-match-funds complete!',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }
}
