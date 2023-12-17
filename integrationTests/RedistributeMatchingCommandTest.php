<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RedistributeMatchingCommandTest extends IntegrationTest
{
    private CampaignFundingRepository $campaignFundingRepository;
    private Donation $donation;

    public function setUp(): void
    {
        parent::setUp();

        $this->setupFakeDonationClient();
        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);
        $this->matchingAdapater = $this->getService(Adapter::class);

        ['campaignId' => $campaignId] =
            $this->addCampaignAndCharityToDB(campaignSfId: $this->randomString(), fundWithAmountInPounds: 0);

        $campaign = $this->getService(\MatchBot\Domain\CampaignRepository::class)->find($campaignId);

        $this->addFunding($campaign->getSalesforceId(), 250, 100); // Pledge
        ['fundId' => $championFundId, 'campaignFundingId' => $championFundCampaignFundingId] =
            $this->addFunding($campaign->getSalesforceId(), 250, 200);
        $championFundCampaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($championFundCampaignFundingId);

        $this->donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '250',
            projectId: 'any project',
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        ), $campaign);
        $this->donation->setTransactionId('pi_xyz');

        $championFundWithdrawal = new FundingWithdrawal($championFundCampaignFunding);
        $championFundWithdrawal->setAmount('250.00');
        $this->donation->addFundingWithdrawal($championFundWithdrawal);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($this->donation);
        $em->flush();
    }

    public function testCommandRedistributesMatchingToUsePledges(): void
    {
        // arrange
        $hook = $this->donation->toHookModel();
        $this->assertSame('250.00', $hook['amountMatchedByChampionFunds']);
        $this->assertSame('0.00', $hook['amountMatchedByPledges']);

        // act
        $command = new RedistributeMatchFunds(
            $this->campaignFundingRepository,
            $this->getService(\MatchBot\Domain\DonationRepository::class),
            $this->getService(LoggerInterface::class)
        );
        $output = new BufferedOutput();
        $command->run(new ArrayInput([]), $output);

        // assert
        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:redistribute-match-funds starting!',
            'Checked 1 donations and redistributed matching for 1',
            'matchbot:redistribute-match-funds complete!',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
        $this->assertSame('250.00', $this->donation->getFundingWithdrawalTotal());
        $hook = $this->donation->toHookModel();
        $this->assertSame('0.00', $hook['amountMatchedByChampionFunds']);
        $this->assertSame('250.00', $hook['amountMatchedByPledges']);
    }
}
