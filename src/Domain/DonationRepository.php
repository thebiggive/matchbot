<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\ORMException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Client\BadRequestException;
use Ramsey\Uuid\Doctrine\UuidGenerator;

class DonationRepository extends SalesforceWriteProxyRepository
{
    /**
     * @param Donation $donation
     * @return bool|void
     */
    public function doPush(SalesforceWriteProxy $donation): bool
    {
        try {
            $salesforceDonationId = $this->getClient()->create($donation);
            $donation->setSalesforceId($salesforceDonationId);
        } catch (BadRequestException $exception) {
            return false;
        }

        return true;
    }

    public function put(Donation $donation): bool
    {
        $this->logInfo('Updating ' . get_class($donation) . ' ' . $donation->getId() . '...');
        $this->prePush($donation);

        $success = $this->getClient()->put($donation);

        $this->postPush($success, $donation);

        return $success;
    }

    /**
     * @param Donation $proxy
     * @return Donation
     */
    public function doPull(SalesforceReadProxy $proxy): SalesforceReadProxy
    {
        throw new \LogicException('Donation data should not currently be pulled from Salesforce');
    }

    public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        /** @var Campaign $campaign */
        $campaign = $this->getEntityManager()->getRepository(Campaign::class)
            ->findOneBy(['salesforceId' => $donationData->projectId]);

        if (!$campaign) {
            throw new \LogicException('Campaign not known');
        }

        $donation = new Donation();
        $donation->setDonationStatus('Pending');
        $donation->setUuid((new UuidGenerator())->generate($this->getEntityManager(), $donation));
        $donation->setCampaign($campaign); // Charity & match expectation determined implicitly from this
        $donation->setAmount((string) $donationData->donationAmount);
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
        $lockStartTime = microtime(true);
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

                $donation->addFundingWithdrawal($withdrawal);
            }
            $this->getEntityManager()->persist($donation);
            $this->getEntityManager()->commit();
            $lockEndTime = microtime(true);
        } catch (ORMException $exception) {
            // Release the lock ASAP, then log what went wrong
            $this->getEntityManager()->rollback();
            $this->logError(
                'ID ' . $donation->getId() . ' got ' . get_class($exception) .
                ' allocating match funds: ' . $exception->getMessage()
            );

            return '0';
        }

        $amountMatched = bcsub($donation->getAmount(), $amountLeftToMatch, 2);
        $this->logInfo('ID ' . $donation->getUuid() . ' allocated match funds totalling ' . $amountMatched);

        // Monitor allocation times so we can get a sense of how risky the locking behaviour is with different DB sizes
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        return $amountMatched;
    }

    public function releaseMatchFunds(Donation $donation): void
    {
        // TODO soon think about what this looks like
    }
}
