<?php

namespace MatchBot\IntegrationTests;

use DateInterval;
use DateTimeImmutable;

/**
 * Test(s) to demonstrate the bug where the integration of retrospective matching and match funds redistribution
 * running at the same time can go wrong.
 */
class FundingAllocationBugProofTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    /**
     * @testWith
     *          [false]
     */
    public function testRunningToCommandsAtOnceCanFundDonationsWrongly(bool $commandsRunAtSameTime): void
    {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $campaign = \MatchBot\Tests\TestCase::someCampaign(charity: $charity, isMatched: true);
        $campaign->setEndDate((new DateTimeImmutable())->sub(new DateInterval('PT1M')));
        $donationA = \MatchBot\Tests\TestCase::someDonation(campaign: $campaign, amount: '10', collected: true, collectedAt: new \DateTimeImmutable());
        $donationB = \MatchBot\Tests\TestCase::someDonation(campaign: $campaign, amount: '10', collected: true, collectedAt: new \DateTimeImmutable());

        $fundA = new \MatchBot\Domain\Fund('GBP', 'some fund', 'som-fund', \MatchBot\Domain\Salesforce18Id::ofFund(self::randomString()), \MatchBot\Domain\FundType::Pledge);
        $campaignFundingA = new \MatchBot\Domain\CampaignFunding($fundA, '100', '100');
        $campaignFundingA->addCampaign($campaign);

        $fundB = new \MatchBot\Domain\Fund('GBP', 'some fund', 'som-fund', \MatchBot\Domain\Salesforce18Id::ofFund(self::randomString()), \MatchBot\Domain\FundType::TopupPledge);
        $campaignFundingB = new \MatchBot\Domain\CampaignFunding($fundB, '200', '100');
        $campaignFundingB->addCampaign($campaign);

        $fundingWithdrawal = new \MatchBot\Domain\FundingWithdrawal($campaignFundingB, $donationA, '1');
        $donationA->addFundingWithdrawal($fundingWithdrawal);

        $this->em->persist($charity);
        $this->em->persist($campaign);
        $this->em->persist($donationA);
        $this->em->persist($donationB);
        $this->em->persist($fundA);
        $this->em->persist($fundB);

        $this->em->persist($campaignFundingA);
        $this->em->persist($campaignFundingB);

        $this->em->persist($fundingWithdrawal);
        $this->em->flush();

        $donationId = $donationA->getId();

        $this->setInContainer(\Symfony\Component\Notifier\ChatterInterface::class, $this->createStub(\Symfony\Component\Notifier\ChatterInterface::class));

        $lockFactory = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore());

        $redistributeCommand = $this->getService(\MatchBot\Application\Commands\RedistributeMatchFunds::class);
        $redistributeCommand->setLockFactory($lockFactory);
        $redistributeCommand->setLogger(new \Psr\Log\NullLogger());

        // Build a completely separate container so the parallel command uses a different EntityManager
        $separateContainer = require __DIR__ . '/../bootstrap.php';
        // Provide required test doubles in the separate container too
        $separateContainer->set(\Symfony\Component\Notifier\ChatterInterface::class, $this->createStub(\Symfony\Component\Notifier\ChatterInterface::class));

        $retroMatchClosure = function () use ($lockFactory, $separateContainer): void {
            $command = $separateContainer->get(\MatchBot\Application\Commands\RetrospectivelyMatch::class);
            $command->setLogger(new \Psr\Log\NullLogger());
            $command->setLockFactory($lockFactory);
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            $tester->execute([]);
        };

        if ($commandsRunAtSameTime) {
            $redistributeCommand->simulatedParallelProcess = $retroMatchClosure;
        } else {
            // run the retrospective matching entirely before the redistrubtion.
            $retroMatchClosure();
        }

        $redistributeTester = new \Symfony\Component\Console\Tester\CommandTester($redistributeCommand);
        $redistributeTester->execute([]);

        $this->em->clear();

        $donationA = $this->getService(\MatchBot\Domain\DonationRepository::class)->find($donationId);
        $this->assertNotNull($donationA);

        // if the two commands were running at the same time then with this particular interleaving of functions
        // the donation A ends up with £20 of matching despite only being a £10 donation. If the retrospective matching
        // ran first and saved its results to the DB before the redistribtuion, then it only gets £10 of matching as it should.
        $this->assertSame($commandsRunAtSameTime ? 20_00 : 10_00, $donationA->matchedAmount()->amountInPence());
    }
}
