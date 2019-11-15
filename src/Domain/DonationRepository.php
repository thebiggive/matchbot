<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\RetryableException;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use Ramsey\Uuid\Doctrine\UuidGenerator;

class DonationRepository extends SalesforceWriteProxyRepository
{
    /** @var CampaignRepository */
    private $campaignRepository;
    /** @var FundRepository */
    private $fundRepository;
    /** @var int */
    private $expirySeconds = 17 * 60; // 17 minutes: 15 min official timed window plus 2 mins grace.
    /** @var int */
    private $maxAllocationTries = 5;

    /**
     * @param Donation $donation
     * @return bool
     */
    public function doCreate(SalesforceWriteProxy $donation): bool
    {
        try {
            $salesforceDonationId = $this->getClient()->create($donation);
            $donation->setSalesforceId($salesforceDonationId);
        } catch (BadRequestException $exception) {
            return false;
        }

        return true;
    }

    /**
     * @param Donation $donation
     * @return bool
     */
    public function doUpdate(SalesforceWriteProxy $donation): bool
    {
        return $this->getClient()->put($donation);
    }

    public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        /** @var Campaign $campaign */
        $campaign = $this->campaignRepository->findOneBy(['salesforceId' => $donationData->projectId]);

        if (!$campaign) {
            // Fetch data for as-yet-unknown campaigns on-demand
            $this->logInfo("Loading unknown campaign ID {$donationData->projectId} on-demand");
            $campaign = new Campaign();
            $campaign->setSalesforceId($donationData->projectId);
            try {
                $campaign = $this->campaignRepository->pull($campaign);
            } catch (ClientException $exception) {
                throw new \UnexpectedValueException('Campaign does not exist');
            }
            $this->fundRepository->pullForCampaign($campaign);

            $this->getEntityManager()->flush();
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
        $allocationDone = false;
        $allocationTries = 0;
        while (!$allocationDone && $allocationTries < $this->maxAllocationTries) {
            try {
                // We want the whole set of `CampaignFunding`s to have a write-ready lock, so the transaction must
                // surround the whole allocation loop. But we can persist the `FundingWithdrawals` outside the lock
                // to keep it quick.
                $lockStartTime = microtime(true);
                $this->getEntityManager()->beginTransaction();

                $newWithdrawals = $this->safelyAllocateFunds($donation);

                $this->getEntityManager()->commit();
                $lockEndTime = microtime(true);

                // Persist funding withdrawals after we've freed up the lock on funds themselves.
                $amountMatched = '0.0';
                foreach ($newWithdrawals as $newWithdrawal) {
                    $this->getEntityManager()->persist($newWithdrawal);
                    $donation->addFundingWithdrawal($newWithdrawal);
                    $amountMatched = bcadd($amountMatched, $newWithdrawal->getAmount(), 2);
                }
                $this->getEntityManager()->flush();

                $allocationDone = true;
            } catch (RetryableException $exception) {
                $this->getEntityManager()->rollback(); // Free up database locks
                $allocationTries++;
                $this->logError(
                    'ID ' . $donation->getId() . ' got RECOVERABLE ' . get_class($exception) .
                    ' allocating match funds: ' . $exception->getMessage() . ' - try #' . $allocationTries
                );
                usleep(random_int(1, 1000000)); // Wait between 0 and 1 seconds before retrying
            } catch (\Exception $exception) { // Includes non-retryable `DBALException`s
                $this->getEntityManager()->rollback(); // Free up database locks
                $this->logError(
                    'ID ' . $donation->getId() . ' got ' . get_class($exception) .
                    ' allocating match funds: ' . $exception->getMessage()
                );
                throw $exception; // Re-throw exception after logging the details if not recoverable
            }
        }

        if (!$allocationDone) {
            $this->logger->error('Donation Create failed to match after '  . $allocationTries . ' tries');

            throw new DomainLockContentionException();
        }

        $this->logInfo('ID ' . $donation->getUuid() . ' allocated match funds totalling ' . $amountMatched);

        // Monitor allocation times so we can get a sense of how risky the locking behaviour is with different DB sizes
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        return $amountMatched;
    }

    public function releaseMatchFunds(Donation $donation): void
    {
        $totalAmountReleased = '0.00';
        $lockStartTime = microtime(true);

        // We need all `CampaignFunding`s to be updated in the same transaction as the withdrawal deletions.
        $this->getEntityManager()->beginTransaction();

        // The point of this is just to lock the fundings we know we will update alongside deletions below.
        // We don't directly use the returned Doctrine objects because `getCampaignFunding()` gets the same ones
        // in the `foreach` loop where we're deleting FundingWithdrawals.
        $this->getEntityManager()->getRepository(CampaignFunding::class)->getDonationFundings($donation);

        try {
            foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                $funding = $fundingWithdrawal->getCampaignFunding();
                $amountAvailable = bcadd($funding->getAmountAvailable(), $fundingWithdrawal->getAmount(), 2);
                $funding->setAmountAvailable($amountAvailable);
                $this->getEntityManager()->remove($fundingWithdrawal);
                $this->getEntityManager()->persist($funding);

                $totalAmountReleased = bcadd($totalAmountReleased, $fundingWithdrawal->getAmount(), 2);
            }
            $this->getEntityManager()->flush();
            $this->getEntityManager()->commit();
            $lockEndTime = microtime(true);
        } catch (DBALException $exception) {
            // TODO implement retries for releasing match funds too

            // Release the lock ASAP, then log what went wrong
            $this->getEntityManager()->rollback();
            $this->logError(
                'ID ' . $donation->getId() . ' got ' . get_class($exception) .
                ' releasing match funds: ' . $exception->getMessage()
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        }

        $this->logInfo("Taking from ID {$donation->getUuid()} released match funds totalling {$totalAmountReleased}");
        $this->logInfo('Deallocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');
    }

    /**
     * @return Donation[]
     */
    public function findWithExpiredMatching(): array
    {
        $cutoff = (new DateTime('now'))->sub(new \DateInterval("PT{$this->expirySeconds}S"));
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->leftJoin('d.fundingWithdrawals', 'fw')
            ->where('d.donationStatus = :expireWithStatus')
            ->andWhere('d.createdAt < :expireBefore')
            ->groupBy('d')
            ->having('COUNT(fw) > 0')
            ->setParameter('expireWithStatus', 'Pending')
            ->setParameter('expireBefore', $cutoff);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param mixed $campaignRepository
     */
    public function setCampaignRepository($campaignRepository): void
    {
        $this->campaignRepository = $campaignRepository;
    }

    /**
     * @param FundRepository $fundRepository
     */
    public function setFundRepository(FundRepository $fundRepository): void
    {
        $this->fundRepository = $fundRepository;
    }

    /**
     * Attempt an allocation of funds. For use inside a transaction as a self-contained unit that can be rolled back
     * and retried.
     *
     * @param Donation $donation
     * @return FundingWithdrawal[]
     */
    private function safelyAllocateFunds(Donation $donation): array
    {
        $amountLeftToMatch = $donation->getAmount();
        $currentFundingIndex = 0;
        /** @var FundingWithdrawal[] $newWithdrawals Track these to persist outside the lock window, to keep it short */
        $newWithdrawals = [];

        /** @var CampaignFunding[] $fundings */
        $fundings = $this->getEntityManager()
            ->getRepository(CampaignFunding::class)
            ->getAvailableFundings($donation->getCampaign());

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
            $withdrawal->setCampaignFunding($funding);
            $withdrawal->setAmount($amountToAllocateNow);
            $newWithdrawals[] = $withdrawal;
        }
        $this->getEntityManager()->persist($donation);

        return $newWithdrawals;
    }
}
