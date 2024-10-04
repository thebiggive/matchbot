<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Matching;
use MatchBot\Client;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;

/**
 * @template-extends SalesforceReadProxyRepository<Fund, Client\Fund>
 */
class FundRepository extends SalesforceReadProxyRepository
{
    private CampaignFundingRepository $campaignFundingRepository;
    private Matching\Adapter $matchingAdapter;

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
     * @throws Client\NotFoundException if Campaign not found on Salesforce
     */
    public function pullForCampaign(Campaign $campaign): void
    {
        $client = $this->getClient();

        $campaignSFId = $campaign->getSalesforceId();
        if ($campaignSFId === null) {
            $this->logWarning(
                'Cannot pullForCampaign() funds for campaign without SF ID for internal ID ' . $campaign->getId()
            );
            return;
        }

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
                $campaignFunding = $this->campaignFundingRepository->getFunding($fund);
            } else {
                $campaignFunding = $this->campaignFundingRepository->getFundingForCampaign($campaign, $fund);
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
                // Note that a balance DECREASE on the API side is unsupported and would be ignored, as this
                // risks invalidating in-progress donation matches.
                $increaseInAmount = bcsub($amountForCampaign, $campaignFunding->getAmount(), 2);

                if (bccomp($increaseInAmount, '0.00', 2) === 1) {
                    $newTotal = $this->matchingAdapter->addAmount($campaignFunding, $increaseInAmount);

                    $this->logger->info(
                        "Funding ID {$campaignFunding->getId()} balance increased " .
                        "£{$increaseInAmount} to £{$newTotal}"
                    );

                    $campaignFunding->setAmount($amountForCampaign);
                }
            } else {
                // Not a previously existing campaign -> create one and set balances without checking for existing ones.
                $campaignFunding = new CampaignFunding();
                $campaignFunding->createdNow();
                $campaignFunding->setFund($fund);
                $campaignFunding->setCurrencyCode($fund->getCurrencyCode());
                $campaignFunding->setAmountAvailable($amountForCampaign);
                $campaignFunding->setAmount($amountForCampaign);
            }

            if ($fund instanceof Pledge) {
                $campaignFunding->setAllocationOrder(100);
            } else {
                $campaignFunding->setAllocationOrder(200);
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
                    ($campaign->getId() ?? '[unknown]') . ', fund ' . $fund->getId()
                );
            }
        }
    }

    protected function setAnyFundData(Fund $fund, array $fundData): Fund
    {
        if ($fund->hasBeenPersisted() && $fund->getCurrencyCode() !== $fundData['currencyCode']) {
            $this->logWarning(sprintf(
                'Refusing to update fund currency to %s for SF ID %s',
                $fundData['currencyCode'],
                $fundData['id'],
            ));

            throw new DomainCurrencyMustNotChangeException();
        }
        $fund->setCurrencyCode($fundData['currencyCode'] ?? 'GBP');
        $fund->setName($fundData['name'] ?? '');
        $fund->setSalesforceLastPull(new DateTime('now'));

        return $fund;
    }

    protected function getNewFund(array $fundData): Fund
    {
        $currencyCode = $fundData['currencyCode'] ?? 'GBP';
        $name = $fundData['name'] ?? '';
        Assertion::string($currencyCode);
        Assertion::string($name);

        if ($fundData['type'] === Pledge::DISCRIMINATOR_VALUE) {
            $fund = new Pledge(currencyCode: $currencyCode, name: $name);
        } elseif ($fundData['type'] === 'championFund') {
            $fund = new ChampionFund(currencyCode: $currencyCode, name: $name);
        } else {
            throw new \UnexpectedValueException("Unknown fund type '{$fundData['type']}'");
        }
        $fund->setSalesforceId($fundData['id']);

        return $fund;
    }

    /**
     * Get live data for the object (which might be empty apart from the Salesforce ID) and return a full object.
     * No need to `setSalesforceLastPull()`, or EM `persist()` - just populate the fields specific to the object.
     *
     * @param Fund $proxy
     */
    protected function doUpdateFromSf(SalesforceReadProxy $proxy, bool $withCache): void
    {
        $fundId = $proxy->getSalesforceId();
        if ($fundId == null) {
            throw new \Exception("Missing ID on fund, cannot update from SF");
        }

        $fundData = $this->getClient()->getById($fundId, $withCache);
        $proxy->setName($fundData['name'] ?? '');
    }
}
