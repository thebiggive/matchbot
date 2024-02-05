<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\OptimisticRedisAdapter;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Application\RedisMatchingStorage;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use Psr\Log\LoggerInterface;
use Redis;

class DonationMatchingTest extends IntegrationTest
{
    private int $campaignFundingId;
    private CampaignFundingRepository $campaignFundingRepository;
    private OptimisticRedisAdapter $matchingAdapater;

    public function setUp(): void
    {
        parent::setUp();

        $this->setupFakeDonationClient();
        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);
        $this->matchingAdapater = $this->getService(OptimisticRedisAdapter::class);
    }

    public function testDonatingReducesAvailableMatchFunds(): void
    {
        // arrange
        ['campaignFundingId' => $this->campaignFundingId, 'campaignId' => $campaignId] =
            $this->addFundedCampaignAndCharityToDB(campaignSfId: $this->randomString(), fundWithAmountInPounds: 100);

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
        $this->setInContainer(OptimisticRedisAdapter::class, $this->matchingAdapater);
        $this->getService(\MatchBot\Domain\DonationRepository::class)->setMatchingAdapter($this->matchingAdapater);

        $campaignInfo = $this->addFundedCampaignAndCharityToDB(
            campaignSfId: $this->randomString(),
            fundWithAmountInPounds: 100,
        );
        ['campaignFundingId' => $this->campaignFundingId, 'campaignId' => $campaignId] = $campaignInfo;

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
            $this->assertEquals(
                'Throwing after subtracting funds to test how our system handles the crash',
                $e->getMessage(),
            );
        }

        // assert
        $amountAvailable = $this->matchingAdapater->getAmountAvailable($campaignFunding);

        $this->assertEquals(100, $amountAvailable); // not reduced
    }

    private function makeAdapterThatThrowsAfterSubtractingFunds(
        OptimisticRedisAdapter $matchingAdapater
    ): OptimisticRedisAdapter {
        return new class ($matchingAdapater) extends OptimisticRedisAdapter {
            private bool $inTransaction = false;

            public function __construct(private OptimisticRedisAdapter $wrappedAdapter)
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

            private function doRunTransactionally(callable $function): mixed
            {
                // call to runTransactionally not doRunTransactionally because the wrappedAdapater has to know that
                // it's in a transaction.
                return $this->wrappedAdapter->runTransactionally($function);
            }

            /**
             * @param CampaignFunding $funding
             * @param string $amount
             * @return string New fund balance as bcmath-ready string
             */
            public function addAmount(CampaignFunding $funding, string $amount): string
            {
                if (!$this->inTransaction) {
                    throw new \LogicException('Matching adapter work must be in a transaction');
                }

                return $this->doAddAmount($funding, $amount);
            }

            private function doAddAmount(CampaignFunding $funding, string $amount): string
            {
                return $this->wrappedAdapter->addAmount($funding, $amount);
            }

            /**
             * @param CampaignFunding $funding
             * @param string $amount
             * @return string New fund balance as bcmath-ready string
             */
            public function subtractAmount(CampaignFunding $funding, string $amount): string
            {
                if (!$this->inTransaction) {
                    throw new \LogicException('Matching adapter work must be in a transaction');
                }

                return $this->doSubtractAmount($funding, $amount);
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

        $this->setInContainer(
            OptimisticRedisAdapter::class,
            new OptimisticRedisAdapter(new RedisMatchingStorage($redis), $entityManager, $logger),
        );
    }
}
