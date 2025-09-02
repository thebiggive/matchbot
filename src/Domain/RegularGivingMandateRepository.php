<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MatchBot\Application\Assertion;
use Ramsey\Uuid\UuidInterface;

/**
 * Note this is different from other repositories we have so far as it encapsulates Doctrine's repository
 * class instead of extending it, so that it can present just the specific API that we actually want and ensure
 * that all the ways we use are listed as methods below.
 *
 * If we like this pattern we could try applying it
 * to all repos automatically - see
 * https://getrector.com/blog/how-to-instantly-decouple-symfony-doctrine-repository-inheritance-to-clean-composition
 */
class RegularGivingMandateRepository
{
    /** @var EntityRepository<RegularGivingMandate>  */
    private EntityRepository $doctrineRepository;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(RegularGivingMandate::class);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - is unused, kept for now in case we want it again.
     * @return list<RegularGivingMandate>
     */
    public function allForDonor(PersonId $donorId): array
    {
        return $this->doctrineRepository->findBy(['donorId.id' => $donorId->id]);
    }

    /**
     * @return list<RegularGivingMandate>
     */
    public function allPendingForDonorAndCampaign(PersonId $donorId, string $campaignSalesforceId): array
    {
        return $this->doctrineRepository->findBy(
            [
                'donorId.id' => $donorId->id,
                'campaignId' => $campaignSalesforceId,
                'status' => 'pending',
            ]
        );
    }

    /**
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     *     List of tuples of regular giving mandates with their recipient charities
     *
     */
    public function allActiveMandatesForDonor(PersonId $donor): array
    {
        $active = MandateStatus::Active->value;

        $query = $this->em->createQuery(<<<"DQL"
            SELECT r, c FROM MatchBot\Domain\RegularGivingMandate r
            LEFT JOIN MatchBot\Domain\Charity c WITH r.charityId = c.salesforceId
            WHERE r.status = '{$active}'
            AND r.donorId.id = :donorId
            ORDER BY r.activeFrom desc
        DQL
        );

        $query->setParameter('donorId', $donor->id);

        return $this->getMandatesWithCharities($query);
    }

    /**
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     *     List of tuples of regular giving mandates with their recipient charities
     *
     * Includes any and all mandates for a donor that they should know or care about. The only exclusions currently are
     * 'pending' mandates, which have not yet been activated, and auto-cancelled mandates that never got to be
     * activated because e.g. there was a payment failure on the first donation.
     */
    public function allMandatesForDisplayToDonor(PersonId $donor): array
    {
        $active = MandateStatus::Active->value;
        $cancelled = MandateStatus::Cancelled->value;
        $campaignEnded = MandateStatus::CampaignEnded->value;

        $donorCancelled = MandateCancellationType::DonorRequestedCancellation->value;
        $bgCancelled = MandateCancellationType::BigGiveCancelled->value;

        // We want to include active mandates, and mandates that *were* active for any amount of time then manually
        // cancelled. Not mandates auto cancelled on creation which may as well never have existed.

        $query = $this->em->createQuery(<<<"DQL"
            SELECT r, c FROM MatchBot\Domain\RegularGivingMandate r
            LEFT JOIN MatchBot\Domain\Charity c WITH r.charityId = c.salesforceId
            WHERE (
                r.status = '{$active}' OR
                r.status = '{$campaignEnded}' OR
                (r.status = '{$cancelled}' AND r.cancellationType IN ('$bgCancelled', '$donorCancelled'))
                )
            AND r.donorId.id = :donorId
            ORDER BY r.activeFrom desc
        DQL
        );

        $query->setParameter('donorId', $donor->id);

        return $this->getMandatesWithCharities($query);
    }

    public function findOneByUuid(UuidInterface $uuid): ?RegularGivingMandate
    {
        return $this->doctrineRepository->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     */
    public function findMandatesWithDonationsToCreateOn(\DateTimeImmutable $now, int $limit): array
    {
        $active = MandateStatus::Active->value;
        // r.donationsCreatedUpTo is only set after a cron creation i.e. usually 3ish months after mandate setup.
        $query = $this->em->createQuery(<<<"DQL"
            SELECT r, c FROM MatchBot\Domain\RegularGivingMandate r 
            LEFT JOIN MatchBot\Domain\Charity c WITH r.charityId = c.salesforceId
            WHERE r.status = '{$active}'
            AND (r.donationsCreatedUpTo IS NULL OR r.donationsCreatedUpTo <= :now)
            ORDER BY r.createdAt ASC
        DQL
        );

        $query->setParameter('now', $now);
        $query->setMaxResults($limit);

        return $this->getMandatesWithCharities($query);
    }

    /**
     * @param \Doctrine\ORM\Query $query . Query must be for mandates and charities jonied together.
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     */
    private function getMandatesWithCharities(\Doctrine\ORM\Query $query) // @phpstan-ignore missingType.generics
    {
        // I'm confused about the missingType.generics error - as far as I can see the Query class is not generic.

        /** @var list<RegularGivingMandate|Charity> $x */
        $x = $query->getResult();

        $mandates = array_filter($x, fn($x) => $x instanceof RegularGivingMandate);

        /** @var Charity[] $charities */
        $charities = [];
        foreach ($x as $entity) {
            if ($entity instanceof Charity) {
                $charities[$entity->getSalesforceId()] = $entity;
            }
        }

        return array_values(array_map(function (RegularGivingMandate $mandate) use ($charities) {
            $charityId = $mandate->getCharityId();
            return [
                $mandate,
                $charities[$charityId] ?? throw new \Exception("Missing charity for mandate " . $mandate->getUuid()->toString())
            ];
        },
            $mandates));
    }

    /**
     * @return list<RegularGivingMandate>
     */
    public function findAllPendingSinceBefore(\DateTimeImmutable $latestCreationDate): array
    {
        $pending = MandateStatus::Pending->value;

        $query = $this->em->createQuery(<<<DQL
                SELECT r FROM MatchBot\Domain\RegularGivingMandate r 
                WHERE r.status = '{$pending}'
                AND r.createdAt <= :latestCreationDate
            DQL
        );
        $query->setParameter('latestCreationDate', $latestCreationDate);
        $query->setMaxResults(20);

        /** @var list<RegularGivingMandate> $mandates */
        $mandates = $query->getResult();
        return $mandates;
    }
}
