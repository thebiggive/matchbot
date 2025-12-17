<?php

namespace MatchBot\Tests\Domain;

use DateTime;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Currency;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class InMemoryDonationRepository implements DonationRepository
{
    /** @var array<int, Donation> */
    private $donations = [];

    #[\Override] public function findAndLockOneByUUID(UuidInterface $donationId): ?Donation
    {
        foreach ($this->donations as $donation) {
            if ($donation->getUuid()->equals($donationId)) {
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

    #[\Override] public function findOneBy(array $criteria): ?Donation
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

        throw new \Exception(
            "Method not implemented in test double with criteria: " .
            json_encode($criteria, \JSON_THROW_ON_ERROR)
        );
    }

    #[\Override]
    public function findAndLockOneBy(array $criteria, ?array $orderBy = null): ?Donation
    {
        return $this->findOneBy($criteria);
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

    #[\Override] public function pushSalesforcePending(\DateTimeImmutable $now, MessageBusInterface $bus): int
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

    #[\Override] public function findDonationsToSetPaymentIntent(\DateTimeImmutable $atDateTime, int $maxBatchSize): array
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

    #[\Override] public function find($id)
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function findBy(array $criteria)
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override]
    public function push(DonationUpserted $changeMessage): void
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override]
    public function findPendingAndPreAuthedForMandate(UuidInterface $mandateId): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override]
    public function findAllForMandate(UuidInterface $mandateId): array
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override]
    public function findOneByUUID(UuidInterface $donationUUID): ?Donation
    {
        return $this->findOneBy(['uuid' => $donationUUID]);
    }

    #[\Override]
    public function findAllByPayoutId(string $payoutId): never
    {
        throw new \Exception("Method not implemented in test double");
    }

    #[\Override] public function countCompleteDonationsToCampaign(Campaign $campaign): int
    {
        return count(\array_filter(
            $this->donations,
            fn(Donation $d) => $d->getCampaign() === $campaign
        ));
    }

    #[\Override]
    public function findOverMatchedDonations(): array
    {
        return \array_values(\array_filter(
            $this->donations,
            fn(Donation $d) => $d->getFundingWithdrawalTotalAsObject()->moreThan(Money::fromNumericString($d->getAmount(), $d->currency()))
        ));
    }
}
