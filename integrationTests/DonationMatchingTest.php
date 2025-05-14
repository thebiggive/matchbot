<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\RedisMatchingStorage;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DoctrineDonationRepository;
use Psr\Log\LoggerInterface;
use Redis;

class DonationMatchingTest extends IntegrationTest
{
    private int $campaignFundingId;
    private CampaignFundingRepository $campaignFundingRepository;
    private Adapter $matchingAdapater;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->campaignFundingRepository = $this->getService(CampaignFundingRepository::class);
        $this->matchingAdapater = $this->getService(Adapter::class);
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
        $this->setInContainer(Adapter::class, $this->matchingAdapater);
        $donationRepository = $this->getService(\MatchBot\Domain\DonationRepository::class);
        Assertion::isInstanceOf($donationRepository, DoctrineDonationRepository::class);
        $donationRepository->setMatchingAdapter($this->matchingAdapater);

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
                amountInPounds: 10,
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
        Adapter $matchingAdapater
    ): Adapter {
        return new class ($matchingAdapater) extends Adapter {
            public function __construct(private Adapter $wrappedAdapter) // @phpstan-ignore constructor.missingParentCall
            {
            }

            #[\Override]
            public function getAmountAvailable(CampaignFunding $funding): string
            {
                return $this->wrappedAdapter->getAmountAvailable($funding);
            }

            #[\Override]
            public function delete(CampaignFunding $funding): void
            {
                $this->wrappedAdapter->delete($funding);
            }

            #[\Override]
            public function subtractAmount(CampaignFunding $funding, string $amount): never
            {
                $this->wrappedAdapter->subtractAmount($funding, $amount);

                throw new \Exception("Throwing after subtracting funds to test how our system handles the crash");
            }

            #[\Override]
            public function releaseNewlyAllocatedFunds(): void
            {
                $this->wrappedAdapter->releaseNewlyAllocatedFunds();
            }
        };
    }

    /** @psalm-suppress MixedArgument */
    #[\Override]
    public function tearDown(): void
    {
        parent::tearDown();
        $c = $this->getContainer();

        $redis = $c->get(Redis::class);
        $logger = $c->get(LoggerInterface::class);

        $this->setInContainer(
            Adapter::class,
            new Adapter(new RedisMatchingStorage($redis), $logger),
        );
    }
}
