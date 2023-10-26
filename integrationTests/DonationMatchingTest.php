<?php

use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFundingRepository;

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
}