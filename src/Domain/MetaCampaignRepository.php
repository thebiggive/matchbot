<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MatchBot\Application\Assertion;

/**
 * @psalm-suppress UnusedProperty - likely to be used soon
*/
class MetaCampaignRepository
{
    /** @var EntityRepository<MetaCampaign>  */
    private EntityRepository $doctrineRepository;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(MetaCampaign::class);
    }

    public function getBySlug(MetaCampaignSlug $slug): ?MetaCampaign
    {
        return $this->doctrineRepository->findOneBy(['slug' => $slug->slug]);
    }

    public function countCompleteDonationsToMetaCampaign(MetaCampaign $metaCampaign): int
    {
        $query = $this->em->createQuery(<<<'DQL'
            SELECT COUNT(d.id)
            FROM MatchBot\Domain\Donation d JOIN d.campaign c
            WHERE c.metaCampaignSlug = :slug
            AND d.donationStatus IN (:collectedStatuses)
        DQL
        );

        $query->setParameter('slug', $metaCampaign->getSlug()->slug);

        $query->setParameter('collectedStatuses', DonationStatus::SUCCESS_STATUSES);

        $count = (int)$query->getSingleScalarResult();

        \assert($count >= 0);

        return $count;
    }

    /**
     * Returns the total of all the complete donations to this metacampaign, including matching and including gift aid,
     * and any "offline" donations or adjustments.
     *
     * Note that this DOES include gift aid - compare {@see CampaignRepository::totalAmountRaised()} which does not.
     */
    public function totalAmountRaised(MetaCampaign $metaCampaign): Money
    {
        $donationQuery = $this->em->createQuery(
            <<<'DQL'
            SELECT donation.currencyCode, COALESCE(SUM(
            donation.amount + 
            (CASE WHEN donation.giftAid = 1 THEN donation.amount * :giftAidPercent / 100 ELSE 0 END)))
             as sum
            FROM MatchBot\Domain\Donation donation JOIN donation.campaign c
            WHERE c.metaCampaignSlug = :slug 
            AND donation.donationStatus IN (:succcessStatus)
            GROUP BY donation.currencyCode
        DQL
        );

        $donationQuery->setParameters([
            'slug' => $metaCampaign->getSlug()->slug,
            'succcessStatus' => DonationStatus::SUCCESS_STATUSES,
            'giftAidPercent' => Donation::GIFT_AID_PERCENTAGE,
        ]);

        /** @var list<array{currencyCode: string, sum: numeric}> $donationResult */
        $donationResult =  $donationQuery->getResult();

        Assertion::maxCount(
            $donationResult,
            1,
            "multiple currency donations found for same campaign, can't calculate sum"
        );

        if ($donationResult === []) {
            $donationSum = '0.00';
        } else {
            $donationSum = $donationResult[0]['sum'];
        }

        Assertion::numeric($donationSum);

        $matchedFundQuery = $this->em->createQuery(
            <<<'DQL'
            SELECT COALESCE(SUM(fw.amount), 0) as sum
            FROM MatchBot\Domain\FundingWithdrawal fw
            JOIN fw.donation donation JOIN donation.campaign c
            WHERE c.metaCampaignSlug = :slug
            AND donation.donationStatus IN (:succcessStatus)
        DQL
        );

        $matchedFundQuery->setParameters([
            'slug' => $metaCampaign->getSlug()->slug,
            'succcessStatus' => DonationStatus::SUCCESS_STATUSES,
        ]);

        $matchedFundResult =  $matchedFundQuery->getSingleScalarResult();
        Assertion::numeric($matchedFundResult);

        $currency = Currency::fromIsoCode($donationResult[0]['currencyCode'] ?? 'GBP');

        $total = Money::sum(
            Money::fromNumericString((string) $donationSum, $currency),
            Money::fromNumericString((string) $matchedFundResult, $currency),
            $metaCampaign->getTotalAdjustment(),
        );

        return $total;
    }
}
