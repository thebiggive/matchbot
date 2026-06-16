<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestCase;

class AllocatorTest extends IntegrationTest
{
    private Allocator $SUT;

    private DonationRepository $donationRepo;

    private Adapter $matchingAdapter;

    public function setUp(): void
    {
        parent::setUp();
        $this->donationRepo = $this->getService(DonationRepository::class);
        $this->matchingAdapter = $this->getService(Adapter::class);
        $this->SUT = $this->getService(Allocator::class);
    }
    public function testItAllocatesMatchFundsForDonation(): void
    {
        // arrange
        $funding = new CampaignFunding($this->someFund(), '500', '500');

        $donationAmount = '100.00';
        $donation = $this->prepareMatchedDonation($donationAmount, $funding);

        $donationId = $donation->getId();

        // act
        $allocated = $this->SUT->allocateMatchFunds($donation);
        $this->em->flush();

        // assert
        $this->em->clear();
        $donation = $this->donationRepo->find($donationId);
        \assert($donation !== null);

        $this->assertSame('100.00', $allocated);
        $this->assertSame('400.00', $this->matchingAdapter->getAmountAvailable($funding));
        $this->assertSame(100.0, $donation->getFundingWithdrawalTotalAsObject()->toMajorUnitFloat());
    }

    public function testItReleasesMatchFundsForDonation(): void
    {
        $funding = new CampaignFunding($this->someFund(), '500', '500');

        $donationAmount = '100.00';
        $donation = $this->prepareMatchedDonation($donationAmount, $funding);

        $donationId = $donation->getId();

        // act
        $this->SUT->allocateMatchFunds($donation);
        $this->em->flush();

        $this->SUT->releaseMatchFunds($donation);

        // assert
        $this->em->clear();
        $donation = $this->donationRepo->find($donationId);
        \assert($donation !== null);

        $this->assertSame('500.00', $this->matchingAdapter->getAmountAvailable($funding));
        $this->assertSame(0.0, $donation->getFundingWithdrawalTotalAsObject()->toMajorUnitFloat());
    }
    public function testReleasingMatchFundsSecondTimeForSameDonationDoesNothing(): void
    {
        $funding = new CampaignFunding($this->someFund(), '500', '500');

        $donationAmount = '100.00';
        $donation = $this->prepareMatchedDonation($donationAmount, $funding);

        $donationId = $donation->getId();

        // act
        $this->SUT->allocateMatchFunds($donation);
        $this->em->flush();

        $this->SUT->releaseMatchFunds($donation);
        $this->SUT->releaseMatchFunds($donation);

        // assert
        $this->em->clear();
        $donation = $this->donationRepo->find($donationId);
        \assert($donation !== null);

        // this tells us what Redis says:
        $this->assertSame('500.00', $this->matchingAdapter->getAmountAvailable($funding));

        // this tells us what MySQL says:
        $this->assertSame(0.0, $donation->getFundingWithdrawalTotalAsObject()->toMajorUnitFloat());
    }

    /**
     * @return Fund
     */
    public function someFund(): Fund
    {
        $fund = new Fund(
            currencyCode: 'GBP',
            name: 'some fund',
            slug: 'smfnd',
            salesforceId: null,
            fundType: FundType::ChampionFund
        );
        return $fund;
    }

    /**
     * @param numeric-string $donationAmount
     */
    public function prepareMatchedDonation(string $donationAmount, CampaignFunding $funding): Donation
    {
        $donation = TestCase::someDonation(amount: $donationAmount);
        $campaign = $donation->getCampaign();
        $funding->addCampaign($campaign);
        $this->em->persist($funding);
        $this->em->persist($campaign->getCharity());
        $this->em->persist($campaign);
        $this->em->persist($donation);
        $this->em->flush();

        return $donation;
    }
}
