<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\OptimisticRedisAdapter;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use Psr\Log\LoggerInterface;

class DonationMatchingTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    private int $campaignFundingId;
    private CampaignFundingRepository $campaignFundingRepository;
    private Adapter $matchingAdapater;

    public function setUp(): void
    {
        parent::setUp();

        $this->setupFakeDonationClient();
        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);
        $this->matchingAdapater = $this->getService(Adapter::class);
    }

    public function testDonatingReducesAvailableMatchFunds(): void
    {
        // arrange
        ['campaignFundingID' => $this->campaignFundingId, 'campaignId' => $campaignId] =
            $this->addCampaignAndCharityToDB(campaignSfId: $this->randomString(), fundWithAmountInPounds: 100);

        $campaign = $this->getService(\MatchBot\Domain\CampaignRepository::class)->find($campaignId);
        Assertion::notNull($campaign);
        $campaignFunding = $this->campaignFundingRepository->find($this->campaignFundingId);
        Assertion::notNull($campaignFunding);

        // act
        $this->createDonation(
            withPremadeCampaign: false,
            campaignSfID: $campaign->getSalesforceId(),
            amountInPounds: 10
        );

        // assert
        $amountAvailable = $this->matchingAdapater->getAmountAvailable($campaignFunding);

        $this->assertEquals(90, $amountAvailable); // 100 originally in fund - 10 matched to donation.
    }

    public function testACrashedDonationDoesNotReduceAvailableMatchFunds(): void
    {
        // arrange
        $this->matchingAdapater = $this->makeAdapterThatThrowsAfterSubtractingFunds($this->matchingAdapater);
        $this->setInContainer(Adapter::class, $this->matchingAdapater);
        $this->getService(\MatchBot\Domain\DonationRepository::class)->setMatchingAdapter($this->matchingAdapater);

        ['campaignFundingID' => $this->campaignFundingId, 'campaignId' => $campaignId] =
            $this->addCampaignAndCharityToDB(campaignSfId: $this->randomString(), fundWithAmountInPounds: 100);

        $campaign = $this->getService(\MatchBot\Domain\CampaignRepository::class)->find($campaignId);
        Assertion::notNull($campaign);
        $campaignFunding = $this->campaignFundingRepository->find($this->campaignFundingId);
        Assertion::notNull($campaignFunding);


        // act
        try {
            $this->createDonation(
                withPremadeCampaign: false,
                campaignSfID: $campaign->getSalesforceId(),
                amountInPounds: 10
            );
        } catch (\Exception $e) {
            $this->assertEquals("Throwing after subtracting funds to test how our system handles the crash", $e->getMessage());
        }

        // assert
        $amountAvailable = $this->matchingAdapater->getAmountAvailable($campaignFunding);

        $this->assertEquals(100, $amountAvailable); // not reduced
    }

    private function makeAdapterThatThrowsAfterSubtractingFunds(Adapter $matchingAdapater): Adapter
    {
        return new class ($matchingAdapater) extends Adapter {
            public function __construct(private Adapter $wrappedAdapter)
            {
            }

            public function getAmountAvailable(CampaignFunding $funding): string
            {
                return $this->wrappedAdapter->getAmountAvailable($funding);
            }

            public function delete(CampaignFunding $funding): void
            {
                $this->wrappedAdapter->delete($funding);
            }

            protected function doRunTransactionally(callable $function)
            {
                // call to runTransactionally not doRunTransactionally because the wrappedAdapater has to know that its in a transaction.
                return $this->wrappedAdapter->runTransactionally($function);
            }

            protected function doAddAmount(CampaignFunding $funding, string $amount): string
            {
                return $this->wrappedAdapter->doAddAmount($funding, $amount);
            }

            protected function doSubtractAmount(CampaignFunding $funding, string $amount): string
            {
                $this->wrappedAdapter->subtractAmount($funding, $amount);

                throw new \Exception("Throwing after subtracting funds to test how our system handles the crash");
            }

            public function releaseNewlyAllocatedFunds(): void
            {
                $this->wrappedAdapter->releaseNewlyAllocatedFunds();
            }
        };
    }

    /** @psalm-suppress MixedArgument */
    public function tearDown(): void
    {
        parent::tearDown();
        $c = $this->getContainer();

        $redis = $c->get(Redis::class);
        $entityManager = $c->get(RetrySafeEntityManager::class);
        $logger = $c->get(LoggerInterface::class);

        \assert($redis instanceof Redis);
        \assert($entityManager instanceof RetrySafeEntityManager);
        \assert($logger instanceof LoggerInterface);

        $this->setInContainer(Adapter::class, new OptimisticRedisAdapter($redis, $entityManager, $logger));
    }
}
