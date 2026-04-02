<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Commands\RetrospectivelyMatch;
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
use MatchBot\Domain\FundRepository;
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

class RetrospectivelyMatchCommandTest extends IntegrationTest
{
    private CampaignFundingRepository $campaignFundingRepository;
    private Campaign $closedCampaign;
    private CampaignFunding $closedCampaignChampionFunding;
    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);

        [$this->closedCampaign, $this->closedCampaignChampionFunding] = $this->prepareCampaign();

        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->willReturnArgument();
    }

    public function testCommandRetrospectivelyMatchesUsingDefaultMode(): void
    {
        // arrange: Setup an unmatched donation
        $donation = $this->prepareUnmatchedDonation(
            $this->closedCampaign,
            250,
        );
        $hook = $donation->toFrontEndApiModel();
        $this->assertSame('0.00', $donation->getFundingWithdrawalTotal());
        $this->assertSame(0.00, $hook['amountMatchedByChampionFunds']);

        // act
        $output = $this->runCommand([]);

        // assert
        $updatedDonation = $this->getService(DonationRepository::class)->find($donation->getId());
        Assertion::notNull($updatedDonation);
        $this->assertSame('250.00', $updatedDonation->getFundingWithdrawalTotal());

        $hook = $updatedDonation->toFrontEndApiModel();
        $this->assertSame(250.00, $hook['amountMatchedByChampionFunds']);

        $expectedOutput = [
            'matchbot:retrospectively-match starting!',
            'Automatically evaluating campaigns which closed in the past hour',
            'Retrospectively matched 1 of 1 donations. £250.00 total new matching, across 1 campaigns.',
        ];

        $actualOutput = $output->fetch();
        foreach ($expectedOutput as $expectedLine) {
            $this->assertStringContainsString($expectedLine, $actualOutput);
        }
    }

    private function prepareInRedis(int $campaignFundingId, int $amount): void
    {
        $campaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($campaignFundingId);
        Assertion::notNull($campaignFunding);
        $matchingAdapter = $this->getService(Adapter::class);

        $matchingAdapter->addAmount($campaignFunding, (string) $amount, null, 'RetrospectivelyMatchCommandTest::prepareInRedis');
    }

    /**
     * Prepare & persist a Collected, fully paid donation with no matching applied.
     */
    private function prepareUnmatchedDonation(
        Campaign $campaign,
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

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($donation);
        $em->flush();

        return $donation;
    }

    /**
     * @psalm-return array{0: Campaign, 1: CampaignFunding}
     */
    private function prepareCampaign(): array
    {
        ['campaignId' => $campaignId] = $this->addCampaignAndCharityToDB(
            campaignSfId: $this->randomString(),
            campaignOpen: false, // Must be recently closed
        );

        $campaign = $this->getService(CampaignRepository::class)->find($campaignId);
        Assertion::notNull($campaign);

        // Force the endDate to precisely within the past hour so it gets picked up
        $campaign->setEndDate(new \DateTimeImmutable('30 minutes ago'));

        $amount = 250;
        ['campaignFundingId' => $championFundCampaignFundingId] = $this->addFunding(
            campaignId: $campaignId,
            amountInPounds: $amount,
            fundType: FundType::ChampionFund,
        );

        $this->prepareInRedis($championFundCampaignFundingId, $amount);

        $championFundCampaignFunding = $this->getService(CampaignFundingRepository::class)
            ->find($championFundCampaignFundingId);
        Assertion::notNull($championFundCampaignFunding);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        return [$campaign, $championFundCampaignFunding];
    }

    public function runCommand(array $arguments): BufferedOutput
    {
        $output = new BufferedOutput();

        $matchFundsRedistributor = new MatchFundsRedistributor(
            allocator: $this->getService(Allocator::class),
            chatter: $this->createStub(ChatterInterface::class),
            donationRepository: $this->getService(DonationRepository::class),
            now: new \DateTimeImmutable('now'),
            campaignFundingRepository: $this->campaignFundingRepository,
            logger: $this->getService(LoggerInterface::class),
            entityManager: $this->getService(EntityManagerInterface::class), // Command needs real EM to sync everything
            bus: $this->messageBusProphecy->reveal(),
        );

        $command = new RetrospectivelyMatch(
            allocator: $this->getService(Allocator::class),
            donationRepository: $this->getService(DonationRepository::class),
            fundRepository: $this->getService(FundRepository::class),
            chatter: $this->createStub(ChatterInterface::class),
            bus: $this->messageBusProphecy->reveal(),
            entityManager: $this->getService(EntityManagerInterface::class),
            matchFundsRedistributor: $matchFundsRedistributor,
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->run(new ArrayInput($arguments), $output);

        return $output;
    }
}
