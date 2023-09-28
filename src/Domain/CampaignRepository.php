<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Client;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;

/**
 * @template-extends SalesforceReadProxyRepository<Campaign>
 */
class CampaignRepository extends SalesforceReadProxyRepository
{
    public const GIFT_AID_ONBOARDED_STATUSES = [
        'Onboarded',
        'Onboarded & Data Sent to HMRC',
        'Onboarded & Approved',
        // We'll always aim to fix data problems with HMRC, so should still plan to claim.
        'Onboarded but HMRC Rejected',
    ];

    public const GIFT_AID_APPROVED_STATUSES = [
        'Onboarded & Approved',
    ];

    /**
     * Gets those campaigns which are live now or recently closed (in the last week),
     * based on their last known end time. This allows for campaigns to receive updates
     * shortly after closure if a decision is made to reopen them soon after the end date,
     * while keeping the number of API calls for regular update runs under control long-term.
     * Technically future campaigns are also included if they are already known to MatchBot,
     * though this would typically only happen after manual API call antics or if a start
     * date was pushed back belatedly after a campaign already started.
     *
     * @return Campaign[]
     */
    public function findRecentAndLive(): array
    {
        $oneWeekAgo = (new DateTime('now'))->sub(new \DateInterval('P7D'));
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('c')
            ->from(Campaign::class, 'c')
            ->where('c.endDate >= :oneWeekAgo')
            ->orderBy('c.createdAt', 'ASC')
            ->setParameter('oneWeekAgo', $oneWeekAgo);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param Campaign $campaign
     * @return Campaign
     * @throws Client\NotFoundException if Campaign not found on Salesforce
     * @throws \Exception if start or end dates' formats are invalid
     */
    protected function doPull(SalesforceReadProxy $campaign): SalesforceReadProxy
    {
        $client = $this->getClient();
        $campaignData = $client->getById($campaign->getSalesforceId());

        if ($campaign->hasBeenPersisted() && $campaign->getCurrencyCode() !== $campaignData['currencyCode']) {
            $this->logWarning(sprintf(
                'Refusing to update campaign currency to %s for SF ID %s',
                $campaignData['currencyCode'],
                $campaignData['id'],
            ));

            throw new DomainCurrencyMustNotChangeException();
        }

        $charity = $this->pullCharity(
            $campaignData['charity']['id'],
            $campaignData['charity']['name'],
            $campaignData['charity']['stripeAccountId'],
            $campaignData['charity']['giftAidOnboardingStatus'],
            $campaignData['charity']['hmrcReferenceNumber'],
            $campaignData['charity']['regulatorRegion'],
            $campaignData['charity']['regulatorNumber'],
        );

        $campaign->setCharity($charity);
        $campaign->setCurrencyCode($campaignData['currencyCode'] ?? 'GBP');
        $campaign->setEndDate(new DateTime($campaignData['endDate']));
        $campaign->setFeePercentage($campaignData['feePercentage']);
        $campaign->setIsMatched($campaignData['isMatched']);
        $campaign->setName($campaignData['title']);
        $campaign->setStartDate(new DateTime($campaignData['startDate']));

        return $campaign;
    }

    /**
     * Upsert a Charity based on ID & name, persist and return it.
     *
     * @throws \Doctrine\ORM\ORMException on failed persist()
     */
    private function pullCharity(
        string $salesforceCharityId,
        string $charityName,
        ?string $stripeAccountId,
        ?string $giftAidOnboardingStatus,
        ?string $hmrcReferenceNumber,
        ?string $regulator,
        ?string $regulatorNumber,
    ): Charity {
        $charity = $this->getEntityManager()
            ->getRepository(Charity::class)
            ->findOneBy(['salesforceId' => $salesforceCharityId]);
        if (!$charity) {
            $charity = new Charity();
            $charity->setSalesforceId($salesforceCharityId);
        }

        $charity->updateFromSFData(
            $charityName,
            $stripeAccountId,
            $hmrcReferenceNumber,
            $giftAidOnboardingStatus,
            $regulator,
            $regulatorNumber,
            new DateTime('now')
        );

        $this->getEntityManager()->persist($charity);

        return $charity;
    }

    protected function getRegulatorHMRCIdentifier(string $regulatorName): ?string
    {
        return match ($regulatorName) {
            'England and Wales' => 'CCEW',
            'Northern Ireland' => 'CCNI',
            'Scotland' => 'OSCR',
            default => null,
        };
    }
}
