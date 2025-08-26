<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;

class RedistributeMatchingCommandTest extends IntegrationTest
{
    private CampaignFundingRepository $campaignFundingRepository;
    private Campaign $openCampaign;
    private Campaign $closedCampaign;
    private CampaignFunding $openCampaignChampionFunding;
    private CampaignFunding $closedCampaignChampionFunding;
    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);

        [$this->openCampaign, $this->openCampaignChampionFunding] = $this->prepareCampaign(true);
        [$this->closedCampaign, $this->closedCampaignChampionFunding] = $this->prepareCampaign(false);

        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->willReturnArgument();
    }

    public function testCommandRedistributesMatchingToUsePledges(): void
    {
        // arrange
        $donation = $this->prepareDonation(
            $this->closedCampaign,
            $this->closedCampaignChampionFunding,
            250,
        );
        $hook = $donation->toFrontEndApiModel();
        $this->assertSame('250.00', $donation->getFundingWithdrawalTotal());
        $this->assertSame(250.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(0.00, $hook['amountMatchedByPledges']);

        // act
        $output = $this->runCommand();

        // assert
        $updatedDonation = $this->getService(DonationRepository::class)->find($donation->getId());
        Assertion::notNull($updatedDonation);
        $this->assertSame('250.00', $updatedDonation->getFundingWithdrawalTotal());
        $hook = $updatedDonation->toFrontEndApiModel();
        $this->assertSame(0.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(250.00, $hook['amountMatchedByPledges']);

        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:redistribute-match-funds starting!',
            'Checked 1 donations and redistributed matching for 1',
            'matchbot:redistribute-match-funds complete!',
            '',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }

    /**
     * Otherwise identical to the above but campaign's still running -> no action yet.
     */
    public function testCommandDoesNotRedistributesMatchingWhileCampaignStillOpen(): void
    {
        // arrange
        $donation = $this->prepareDonation(
            $this->openCampaign,
            $this->openCampaignChampionFunding,
            250,
        );
        $hook = $donation->toFrontEndApiModel();
        $this->assertSame('250.00', $donation->getFundingWithdrawalTotal());
        $this->assertSame(250.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(0.00, $hook['amountMatchedByPledges']);

        // act
        $output = $this->runCommand();

        // assert
        $updatedDonation = $this->getService(DonationRepository::class)->find($donation->getId());
        Assertion::notNull($updatedDonation);
        $this->assertSame('250.00', $updatedDonation->getFundingWithdrawalTotal());
        $hook = $updatedDonation->toFrontEndApiModel();
        $this->assertSame(250.00, $hook['amountMatchedByChampionFunds']);
        $this->assertSame(0.00, $hook['amountMatchedByPledges']);

        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:redistribute-match-funds starting!',
            'Checked 0 donations and redistributed matching for 0',
            'matchbot:redistribute-match-funds complete!',
            '',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }

    private function prepareInRedis(int $campaignFundingId, int $amount): void
    {
        $campaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($campaignFundingId);
        Assertion::notNull($campaignFunding);
        $matchingAdapter = $this->getService(Adapter::class);

        // Also calls Doctrine model's `setAmountAvailable()` in a not-guaranteed-realtime way.
        $matchingAdapter->addAmount($campaignFunding, (string) $amount);
    }

    /**
     * Prepare & persist a Collected Donation to the given $campaign with £250 champ funds withdrawn
     * from the match pot, and no pledge funds; and flush the entity manager.
     *
     * @param int $amount In whole GBP units.
     */
    private function prepareDonation(
        Campaign $campaign,
        CampaignFunding $championFundCampaignFunding,
        int $amount,
    ): Donation {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: (string)$amount,
            projectId: 'projectid012345678',
            psp: 'stripe',
            pspMethodType: PaymentMethodType::Card,
        ), $campaign, PersonId::nil());
        $randomizer = new Randomizer();

        $donation->setTransactionId('pi_' . $this->randomString());
        $donation->setSalesforceId(substr('006' . $this->randomString(), 0, 18));
        $donation->collectFromStripeCharge(
            chargeId: 'chg' . $randomizer->getBytesFromString('0123456789abcdef', 10),
            totalPaidFractional: (int)((float)$amount * 100.0),
            transferId: 'tsf' . $randomizer->getBytesFromString('0123456789abcdef', 10),
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: time(),
        );

        $championFundWithdrawal = new FundingWithdrawal($championFundCampaignFunding, $donation, (string)$amount);

        // Not really sure why fixture has to do this both ways around, but re-loading the object
        // and doing just one both seemed to cause problems.
        $donation->addFundingWithdrawal($championFundWithdrawal);

        // Withdraw the donation value from the champion fund in Redis.
        $matchingAdapter = $this->getService(Adapter::class);
        $matchingAdapter->subtractAmount($championFundCampaignFunding, (string) $amount);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($championFundWithdrawal);
        $em->persist($donation);
        $em->flush();

        return $donation;
    }

    /**
     * @psalm-return array{0: Campaign, 1: CampaignFunding}
     */
    private function prepareCampaign(bool $isOpen): array
    {
        ['campaignId' => $campaignId] = $this->addCampaignAndCharityToDB(
            campaignSfId: $this->randomString(),
            campaignOpen: $isOpen,
        );

        $campaign = $this->getService(CampaignRepository::class)->find($campaignId);
        Assertion::notNull($campaign);

        $amount = 250; // For both funds.
        ['campaignFundingId' => $pledgeCampaignFundingId] = $this->addFunding(
            campaignId: $campaignId,
            amountInPounds: $amount,
            fundType: FundType::Pledge,
        );
        ['campaignFundingId' => $championFundCampaignFundingId] = $this->addFunding(
            campaignId: $campaignId,
            amountInPounds: $amount,
            fundType: FundType::ChampionFund,
        );

        $this->prepareInRedis($pledgeCampaignFundingId, $amount);
        $this->prepareInRedis($championFundCampaignFundingId, $amount);

        $championFundCampaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($championFundCampaignFundingId);
        Assertion::notNull($championFundCampaignFunding);

        return [$campaign, $championFundCampaignFunding];
    }

    public function runCommand(): BufferedOutput
    {
        $output = new BufferedOutput();
        $command = new RedistributeMatchFunds(
            new MatchFundsRedistributor(
                allocator: $this->getService(Allocator::class),
                chatter: $this->createStub(ChatterInterface::class),
                donationRepository: $this->getService(DonationRepository::class),
                now: new \DateTimeImmutable('now'),
                campaignFundingRepository: $this->campaignFundingRepository,
                logger: $this->getService(LoggerInterface::class),
                entityManager: $this->createStub(EntityManagerInterface::class),
                bus: $this->messageBusProphecy->reveal(),
            ),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->run(new ArrayInput([]), $output);

        return $output;
    }
}
