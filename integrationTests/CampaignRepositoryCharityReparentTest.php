<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

/**
 * Covers the case where a campaign has been moved to a different charity (Account) in Salesforce since we first
 * pulled it, e.g. because a duplicate Account was merged. Previously we would overwrite the *old* charity record
 * with the new charity's details, which crashed with a unique constraint violation on `stripeAccountId` when the
 * new charity was already known to MatchBot.
 */
class CampaignRepositoryCharityReparentTest extends IntegrationTest
{
    public function testItReassignsCampaignToExistingCharityWhenSalesforceCharityChanges(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $oldCharity = TestCase::someCharity(
            stripeAccountId: 'acct_old' . $this->randomString(),
            salesforceId: Salesforce18Id::ofCharity('001old' . bin2hex(random_bytes(6))),
            name: 'Old duplicate charity record',
        );
        $newStripeAccountId = 'acct_new' . $this->randomString();
        $newCharity = TestCase::someCharity(
            stripeAccountId: $newStripeAccountId,
            salesforceId: Salesforce18Id::ofCharity('001new' . bin2hex(random_bytes(6))),
            name: 'Surviving charity record',
        );
        $campaign = TestCase::someCampaign(charity: $oldCharity);

        $em->persist($oldCharity);
        $em->persist($newCharity);
        $em->persist($campaign);
        $em->flush();

        $campaignData = TestCase::CAMPAIGN_FROM_SALESFORCE;
        $campaignData['id'] = $campaign->getSalesforceId();
        $campaignData['parentRef'] = 'dummy-metacampaign-slug';
        $campaignData['charity']['id'] = $newCharity->getSalesforceId();
        $campaignData['charity']['name'] = 'Surviving charity record (renamed in SF)';
        $campaignData['charity']['stripeAccountId'] = $newStripeAccountId;

        // act
        $sut->updateCampaignFromSFData($campaign, $campaignData);
        $em->flush();
        $em->clear();

        // assert
        $reloadedCampaign = $sut->findOneBySalesforceId(Salesforce18Id::ofCampaign($campaign->getSalesforceId()));
        \assert($reloadedCampaign instanceof Campaign);
        $this->assertSame($newCharity->getSalesforceId(), $reloadedCampaign->getCharity()->getSalesforceId());
        $this->assertSame('Surviving charity record (renamed in SF)', $reloadedCampaign->getCharity()->getName());

        $reloadedOldCharity = $em->getRepository(Charity::class)->findOneBy(['salesforceId' => $oldCharity->getSalesforceId()]);
        \assert($reloadedOldCharity instanceof Charity);
        $this->assertSame('Old duplicate charity record', $reloadedOldCharity->getName());
        $this->assertSame($oldCharity->getStripeAccountId(), $reloadedOldCharity->getStripeAccountId());
    }

    public function testItCreatesNewCharityWhenSalesforceCharityChangesToUnknownOne(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $oldCharity = TestCase::someCharity(
            salesforceId: Salesforce18Id::ofCharity('001old' . bin2hex(random_bytes(6))),
            name: 'Old charity record',
        );
        $campaign = TestCase::someCampaign(charity: $oldCharity);

        $em->persist($oldCharity);
        $em->persist($campaign);
        $em->flush();

        $newCharitySfId = '001new' . bin2hex(random_bytes(6));
        $campaignData = TestCase::CAMPAIGN_FROM_SALESFORCE;
        $campaignData['id'] = $campaign->getSalesforceId();
        $campaignData['parentRef'] = 'dummy-metacampaign-slug';
        $campaignData['charity']['id'] = $newCharitySfId;
        $campaignData['charity']['name'] = 'Brand new charity record';
        $campaignData['charity']['stripeAccountId'] = 'acct_brandnew' . $this->randomString();

        // act
        $sut->updateCampaignFromSFData($campaign, $campaignData);
        $em->flush();
        $em->clear();

        // assert
        $reloadedCampaign = $sut->findOneBySalesforceId(Salesforce18Id::ofCampaign($campaign->getSalesforceId()));
        \assert($reloadedCampaign instanceof Campaign);
        $this->assertSame($newCharitySfId, $reloadedCampaign->getCharity()->getSalesforceId());
        $this->assertSame('Brand new charity record', $reloadedCampaign->getCharity()->getName());

        $reloadedOldCharity = $em->getRepository(Charity::class)->findOneBy(['salesforceId' => $oldCharity->getSalesforceId()]);
        \assert($reloadedOldCharity instanceof Charity);
        $this->assertSame('Old charity record', $reloadedOldCharity->getName());
    }
}
