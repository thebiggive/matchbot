<?php

namespace MatchBot\Tests\Domain;

use DateTime;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class InMemoryDonationRepository implements DonationRepository
{
    /** @var array<int, Donation> */
    private $donations = [];

    /**
     * @var numeric-string
     */
    private string $matchFundsReleased = '0';

    #[\Override] public function findAndLockOneByUUID(UuidInterface $donationId): ?Donation
    {
        foreach ($this->donations as $donation) {
            if ($donation->getUUID()->equals($donationId)) {
                return $donation;
            }
        }

        return null;
    }

    public function store(Donation $donation): void
    {
        if ($donation->getId() === null) {
            $donation->setId(random_int(1000, 99999));
        }
        $id = $donation->getId();

        \assert(is_int($id));

        $this->donations[$id] = $donation;
    }

    #[\Override] public function findOneBy(array $criteria, ?array $orderBy = null): ?Donation
    {
        if (array_keys($criteria) === ['transactionId']) {
            foreach ($this->donations as $donation) {
                if ($donation->getTransactionId() === $criteria['transactionId']) {
                    return $donation;
                }
            }
            return null;
        }

        if (array_keys($criteria) === ['chargeId']) {
            foreach ($this->donations as $donation) {
                if ($donation->getChargeId() === $criteria['chargeId']) {
                    return $donation;
                }
            }
            return null;
        }


        if (array_keys($criteria) === ['uuid']) {
            foreach ($this->donations as $donation) {
                /** @psalm-suppress MixedArgument */
                if ($donation->getUuid()->equals($criteria['uuid'])) {
                    return $donation;
                }
            }
            return null;
        }

        throw new \Exception("Method not implemented in test double with criteria: " . json_encode($criteria));
    }

    #[\Override]
    public function findAndLockOneBy(array $criteria, ?array $orderBy = null): ?Donation
    {
        return $this->findOneBy($criteria);
    }

    /**
     * @return numeric-string
     */
    public function totalMatchFundsReleased(): string
    {
        return $this->matchFundsReleased;
    }

    #[\Override] public function releaseMatchFunds(Donation $donation): void
    {
        $this->matchFundsReleased = bcadd($this->matchFundsReleased, $donation->getAmount());
    }

    #[\Override] public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function allocateMatchFunds(Donation $donation): string
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findWithExpiredMatching(\DateTimeImmutable $now): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(\DateTimeImmutable $campaignsClosedBefore, \DateTimeImmutable $donationsCollectedAfter): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findReadyToClaimGiftAid(bool $withResends): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findNotFullyMatchedToCampaignsWhichClosedSince(DateTime $closedSinceDate): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findRecentNotFullyMatchedToMatchCampaigns(DateTime $sinceDate): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findWithTransferIdInArray(array $transferIds): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findWithFeePossiblyOverchaged(): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function getRecentHighVolumeCompletionRatio(\DateTimeImmutable $nowish): ?float
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function countDonationsCreatedInMinuteTo(\DateTimeImmutable $end): int
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function countDonationsCollectedInMinuteTo(\DateTimeImmutable $end): int
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function abandonOldCancelled(): int
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function setCampaignRepository(CampaignRepository $campaignRepository): void
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function removeAllFundingWithdrawalsForDonation(Donation $donation): void
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function pushSalesforcePending(\DateTimeImmutable $now, MessageBusInterface $bus, DonationService $donationService): int
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findAllCompleteForCustomer(StripeCustomerId $stripeCustomerId): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findPreAuthorizedDonationsReadyToConfirm(\DateTimeImmutable $atDateTime, int $limit): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function maxSequenceNumberForMandate(int $mandateId): ?DonationSequenceNumber
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findStaleDonationFundsTips(\DateTimeImmutable $atDateTime, \DateInterval $cancelationDelay): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findPendingByDonorCampaignAndMethod(string $donorStripeId, Salesforce18Id $campaignId, PaymentMethodType $paymentMethodType,): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function push(AbstractStateChanged $changeMessage, bool $isNew): void
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function find($id)
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findBy(array $criteria)
    {
        throw new \Exception("Method not implemented in test double");
    }
}
