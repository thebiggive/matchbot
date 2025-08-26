<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Matching;
use MatchBot\Client;
use MatchBot\Domain\DomainException\DisallowedFundTypeChange;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;

/**
 * @psalm-import-type fundArray from Client\Fund
 * @psalm-suppress UnusedProperty
 * @template-extends SalesforceReadProxyRepository<Fund, Client\Fund>
 */
class FundRepository extends SalesforceReadProxyRepository
{
    private ?CampaignFundingRepository $campaignFundingRepository = null;
    // @phpstan-ignore property.onlyWritten
    private ?Matching\Adapter $matchingAdapter = null;

    public function setCampaignFundingRepository(CampaignFundingRepository $repository): void
    {
        $this->campaignFundingRepository = $repository;
    }

    /**
     * @param Matching\Adapter $matchingAdapter
     */
    public function setMatchingAdapter(Matching\Adapter $matchingAdapter): void
    {
        $this->matchingAdapter = $matchingAdapter;
    }

    /**
     * @param Campaign  $campaign
     * @param DateTimeImmutable $at
     * @psalm-suppress PossiblyUnusedParam
     * @psalm-suppress UnevaluatedCode
     */
    public function pullForCampaign(Campaign $campaign, \DateTimeImmutable $at): void
    {
        return; // TODO Turn back on later on 26/8/25.

        // @phpstan-ignore deadCode.unreachable
        $client = $this->getClient();

        $campaignSFId = $campaign->getSalesforceId();

        $fundsData = $client->getForCampaign($campaignSFId);
        foreach ($fundsData as $fundData) {
            // For each fund linked to the campaign, look it up or create it if unknown
            $fund = $this->findOneBy(['salesforceId' => $fundData['id']]);
            if (!$fund) {
                $fund = $this->getNewFund($fundData);
            }

            // Then whether new or existing, set its key info
            try {
                $this->setAnyFundData($fund, $fundData);
            } catch (DomainCurrencyMustNotChangeException $exception) {
                return; // No-op w.r.t matching if fund currency changed unexpectedly.
            }

            try {
                $this->getEntityManager()->persist($fund);
                $this->getEntityManager()->flush(); // Need the fund ID for the CampaignFunding find
            } catch (UniqueConstraintViolationException $exception) {
                // Somebody else made the fund with this SF ID during the previous operations.
                $this->logError('Skipping fund create as unique constraint failed on SF ID ' . $fundData['id']);

                $fund = $this->findOneBy(['salesforceId' => $fundData['id']]);
                \assert($fund !== null); // since someone else made it it must now be in the db.
                $fund = $this->setAnyFundData($fund, $fundData);
                $this->getEntityManager()->persist($fund);
            }

            // If there's already a CampaignFunding for this fund, use that regardless of existing campaigns
            // iff the fund is shareable. Otherwise look up only fundings for this campaign. In both cases,
            // if the funding is new the lookup result is null and we must make a new funding.
            if ($fundData['isShared']) {
                $campaignFunding = $this->getCampaignFundingRepository()->getFunding($fund);
            } else {
                $campaignFunding = $this->getCampaignFundingRepository()->getFundingForCampaign($campaign, $fund);
            }

            // We must now support funds' totals changing over time, even after a campaign opens. This must play
            // well with high volume real-time adapters, so we must first check for a likely change and then push the
            // change to the matching adapter when needed.
            /** @psalm-var numeric-string $amountForCampaign */
            $amountForCampaign = $fundData['amountForCampaign'] === null
                ? '0.00'
                : (string) $fundData['amountForCampaign'];

            if ($campaignFunding) {
                // Existing funding -> check for balance increase and apply any in a high-volume-safe way.
                // Note that a balance DECREASE after campaign open time is unsupported and would be error
                // logged below, as this risks invalidating in-progress donation matches.
                $increaseInAmount = bcsub($amountForCampaign, $campaignFunding->getAmount(), 2);

                if (bccomp($increaseInAmount, '0.00', 2) === 1) {
                    $matchingAdapter = $this->matchingAdapter;
                    if ($matchingAdapter === null) {
                        throw new \Exception("Matching Adapter not set");
                    }

                    $newTotal = $matchingAdapter->addAmount($campaignFunding, $increaseInAmount);

                    $this->getLogger()->info(
                        "Funding ID {$campaignFunding->getId()} balance increased " .
                        "£{$increaseInAmount} to £{$newTotal}"
                    );

                    $campaignFunding->setAmount($amountForCampaign);
                }

                if (bccomp($increaseInAmount, '0.00', 2) === -1 && $campaign->getStartDate() < $at) {
                    $this->getLogger()->error(
                        "Funding ID {$campaignFunding->getId()} balance could not be negative-increased by " .
                        "£{$increaseInAmount}. Salesforce Fund ID {$fundData['id']} as campaign {$campaignSFId} opened in past"
                    );
                }
            } else {
                // Not a previously existing campaign -> create one and set balances without checking for existing ones.
                $campaignFunding = new CampaignFunding(
                    fund: $fund,
                    amount: $amountForCampaign,
                    amountAvailable: $amountForCampaign,
                );
            }

            // Make the CampaignFunding available to the Campaign. This method is immutable and won't add duplicates
            // if a campaign is already among those linked to the CampaignFunding.
            $campaignFunding->addCampaign($campaign);

            try {
                $this->getEntityManager()->persist($campaignFunding);
                $this->getEntityManager()->flush();
            } catch (UniqueConstraintViolationException $exception) {
                // Somebody else created the specific funding -> proceed without modifying it.
                $this->logError(
                    'Skipping campaign funding create as constraint failed with campaign ' .
                    ($campaign->getId() ?? '[unknown]') . ', fund ' . ($fund->getId() ?? -1)
                );
            }
        }
    }

    /**
     * @param array{currencyCode: ?string, name: ?string, slug: ?string, type: string, id:string, ...} $fundData
     */
    // @phpstan-ignore method.unused
    private function setAnyFundData(Fund $fund, array $fundData): Fund
    {
        $currencyCode = $fundData['currencyCode'] ?? 'GBP';

        if ($fund->hasBeenPersisted() && $fund->getCurrencyCode() !== $currencyCode) {
            $this->logWarning(sprintf(
                'Refusing to update fund currency to %s for SF ID %s',
                $currencyCode,
                $fundData['id'],
            ));

            throw new DomainCurrencyMustNotChangeException();
        }

        // For now, we let some charities collect non-Topup pledges with the Salesforce form, only
        // after champion funds are used. So the mid-campaign matching behaviour is "usually" unaffected
        // by the Topup status (with possible exceptions if e.g. there are refunds that release champion funds).
        // At the end of a campaign however we want to treat them as Topups during redistribution. So until we have
        // a form that gets it right from the start, we allow a type change from Pledge to TopupPledge based on
        // Salesforce Pledge__c record edits.
        $type = $fundData['type'];
        try {
            $fund->changeTypeIfNecessary(FundType::from($type));
        } catch (DisallowedFundTypeChange $exception) {
            $this->logError(sprintf(
                'Refusing to update fund type to %s for SF ID %s',
                $type,
                $fundData['id'],
            ));
        }

        $fund->setCurrencyCode($currencyCode);
        $fund->setName($fundData['name'] ?? '');
        $fund->setSlug($fundData['slug']);
        $fund->setSalesforceLastPull(new DateTime('now'));

        return $fund;
    }

    /**
     * @param fundArray $fundData
     * @psalm-suppress PossiblyUnusedMethod
     */
    protected function getNewFund(array $fundData): Fund
    {
        $currencyCode = $fundData['currencyCode'] ?? 'GBP';
        $name = $fundData['name'] ?? '';
        $slug = $fundData['slug'];
        $type = $fundData['type'];
        $id = $fundData['id'];
        $fund = new Fund(
            currencyCode: $currencyCode,
            name: $name,
            slug: $slug,
            salesforceId: Salesforce18Id::ofFund($id),
            fundType: FundType::from($type),
        );

        return $fund;
    }

    /**
     * @param DateTime $closedBeforeDate Typically now
     * @param DateTime $closedSinceDate Typically 1 hour ago as determined at the point retro match script started
     * @return Fund[]
     */
    public function findForCampaignsClosedSince(DateTime $closedBeforeDate, DateTime $closedSinceDate): array
    {
        $query = <<<EOT
            SELECT fund FROM MatchBot\Domain\Fund fund
            JOIN fund.campaignFundings campaignFunding
            JOIN campaignFunding.campaigns campaign
            WHERE
                campaign.endDate < :closedBeforeDate AND
                campaign.endDate > :closedSinceDate
            GROUP BY fund
EOT;

        /** @var Fund[] $result */
        $result = $this->getEntityManager()->createQuery($query)
            ->setParameter('closedBeforeDate', $closedBeforeDate)
            ->setParameter('closedSinceDate', $closedSinceDate)
            ->disableResultCache()
            ->getResult();

        return $result;
    }

    /**
     * @param DateTimeImmutable $openAtDate Typically now
     * @return Fund[]
     */
    public function findForCampaignsOpenAt(DateTimeImmutable $openAtDate): array
    {
        $query = <<<EOT
            SELECT fund FROM MatchBot\Domain\Fund fund
            JOIN fund.campaignFundings campaignFunding
            JOIN campaignFunding.campaigns campaign
            WHERE
                campaign.startDate < :openAtDate AND
                campaign.endDate > :openAtDate
            GROUP BY fund
        EOT;

        /** @var Fund[] $result */
        $result = $this->getEntityManager()->createQuery($query)
            ->setParameter('openAtDate', $openAtDate)
            ->disableResultCache()
            ->getResult();

        return $result;
    }

    // @phpstan-ignore method.unused
    private function getCampaignFundingRepository(): CampaignFundingRepository
    {
        return $this->campaignFundingRepository ?? throw new \Exception('CampaignFundingRepository not set');
    }

    /**
     * @return Fund[]
     */
    public function findOldTestPledges(DateTimeImmutable $olderThan): array
    {
        $query = <<<DQL
            SELECT fund FROM MatchBot\Domain\Fund fund
            WHERE
                fund.createdAt < :olderThan AND
                fund.fundType IN (:fundTypes)
        DQL;

        /** @var Fund[] $result */
        $result = $this->getEntityManager()->createQuery($query)
            ->setParameter('olderThan', $olderThan)
            ->setParameter('fundTypes', [FundType::Pledge, FundType::TopupPledge])
            ->getResult();

        return $result;
    }
}
