<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\ORMException;
use MatchBot\Application\DonationCreatePayload;

class DonationRepository extends SalesforceWriteProxyRepository
{
    /**
     * @param Donation $donation
     * @return bool|void
     */
    public function doPush(SalesforceWriteProxy $donation): bool
    {
        $this->getClient()->create($donation);
        // TODO push with Salesforce API client
    }

    /**
     * @param Donation $proxy
     * @return Donation
     */
    public function doPull(SalesforceReadProxy $proxy): SalesforceReadProxy
    {
        throw new \LogicException('Donation data should not currently be pulled from Salesforce');
    }

    public function buildFromApiRequest(DonationCreatePayload $donationData): Donation
    {
        /** @var Campaign $campaign */
        $campaign = $this->getEntityManager()->getRepository(Campaign::class)
            ->findOneBy(['salesforceId' => $donationData->projectId]);

        $donation = new Donation();
        $donation->setCampaign($campaign); // Charity & match expectation determined implicitly from this
        $donation->setAmount($donationData->donationAmount);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setTbgComms($donationData->optInTbgEmail);

        return $donation;
    }

    /**
     * Create all funding allocations, with `FundingWithdrawal` links to this donation, and safely update the funds'
     * available amount figures.
     *
     * @param Donation $donation
     * @return string Total amount of matching allocated
     * @see CampaignFundingRepository::getAvailableFundings() for lock acquisition detail
     */
    public function allocateMatchFunds(Donation $donation): string
    {
        $amountLeftToMatch = $donation->getAmount();
        $currentFundingIndex = 0;

        // We want the whole set of `CampaignFunding`s to have a write-ready lock, so the transaction must surround the
        // whole allocation loop.
        $this->getEntityManager()->beginTransaction();
        /** @var CampaignFunding[] $fundings */
        $fundings = $this->getEntityManager()
            ->getRepository(CampaignFunding::class)
            ->getAvailableFundings($donation->getCampaign());

        try {
            // Loop as long as there are still campaign funds not allocated and we have allocated less than the donation
            // amount
            while ($currentFundingIndex < count($fundings) && bccomp($amountLeftToMatch, '0.00', 2) === 1) {
                $funding = $fundings[$currentFundingIndex];

                $startAmountAvailable = $funding->getAmountAvailable();
                if (bccomp($funding->getAmountAvailable(), $amountLeftToMatch, 2) === -1) {
                    $amountToAllocateNow = $startAmountAvailable;
                } else {
                    $amountToAllocateNow = $amountLeftToMatch;
                }

                $amountLeftToMatch = bcsub($amountLeftToMatch, $amountToAllocateNow, 2);

                $funding->setAmountAvailable(bcsub($startAmountAvailable, $amountToAllocateNow, 2));
                $this->getEntityManager()->persist($funding);

                $withdrawal = new FundingWithdrawal();
                $withdrawal->setDonation($donation);
                $withdrawal->setAmount($amountToAllocateNow);
                $this->getEntityManager()->persist($withdrawal);
            }
            $this->getEntityManager()->commit();
        } catch (ORMException $exception) {
            // TODO log this
            $this->getEntityManager()->rollback();
        }

        // TODO log matching allocations in general? - total amount would be handy to see at a glance

        return bcsub($donation->getAmount(), $amountLeftToMatch, 2);
    }
}
