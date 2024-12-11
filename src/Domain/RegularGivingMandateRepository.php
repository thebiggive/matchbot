<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     *     List of tuples of regular giving mandates with their recipient charities
     */
    public function allActiveForDonorWithCharities(PersonId $donor): array
    {
        $active = MandateStatus::Active->value;
        $query = $this->em->createQuery(<<<"DQL"
            SELECT r, c FROM MatchBot\Domain\RegularGivingMandate r 
            LEFT JOIN MatchBot\Domain\Charity c WITH r.charityId = c.salesforceId
            WHERE r.status = '{$active}'
            AND r.donorId.id = :donorId
        DQL
        );

        $query->setParameter('donorId', $donor->id);

        return $this->getMandatesWithCharities($query);
    }

    public function findOneByUuid(string $uuid) : ?RegularGivingMandate
    {
        return $this->doctrineRepository->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @return list<array{0: RegularGivingMandate, 1: Charity}>
     */
    public function findMandatesWithDonationsToCreateOn(\DateTimeImmutable $now, int $limit): array
    {
        $active = MandateStatus::Active->value;
        $query = $this->em->createQuery(<<<"DQL"
            SELECT r, c FROM MatchBot\Domain\RegularGivingMandate r 
            LEFT JOIN MatchBot\Domain\Charity c WITH r.charityId = c.salesforceId
            WHERE r.status = '{$active}'
            AND (r.donationsCreatedUpTo IS NULL OR r.donationsCreatedUpTo <= :now)
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
    private function getMandatesWithCharities(\Doctrine\ORM\Query $query)
    {
        /** @var list<RegularGivingMandate|Charity> $x */
        $x = $query->getResult();

        $mandates = array_filter($x, fn($x) => $x instanceof RegularGivingMandate);

        /** @var Charity[] $charities */
        $charities = [];
        foreach ($x as $entity) {
            if ($entity instanceof Charity) {
                $salesforceId = $entity->getSalesforceId();
                \assert($salesforceId !== null);

                $charities[$salesforceId] = $entity;
            }
        }

        return array_values(array_map(function (RegularGivingMandate $mandate) use ($charities) {
            $charityId = $mandate->getCharityId();
            return [
                $mandate,
                $charities[$charityId] ?? throw new \Exception("Missing charity for mandate " . $mandate->getUuid())
            ];
        },
            $mandates));
    }
}
