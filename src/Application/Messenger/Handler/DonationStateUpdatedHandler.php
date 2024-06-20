<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

class DonationStateUpdatedHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private DonationRepository $donationRepository,
        private RetrySafeEntityManager $em,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationStateUpdated $donationStateUpdated, Acknowledger $ack = null)
    {
        $this->logger->info("DSUH invoked for" . $donationStateUpdated->donationUUID);
        if (! $this->em->isOpen()) {
            // We assume this same EM is in use by the donation repository, so needs resetting to allow that to work.
            $this->em->resetManager();
        }
        return $this->handle($donationStateUpdated, $ack);
    }

    /**
     * @param list<array{0: DonationStateUpdated, 1: Acknowledger}> $jobsForThisDonation
     */
    private function pushOneDonation(string $donationUUID, array $jobsForThisDonation): void
    {
        $this->logger->info("DSUH pushOneDonation invoked for $donationUUID");

        /** @psalm-suppress MixedPropertyFetch - allSatisfy isn't written with generics */
        Assertion::allSatisfy(
            $jobsForThisDonation,
            static fn(array $job) => $job[0]->donationUUID === $donationUUID
        );

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

        if ($donation === null) {
            $this->logger->info("Null Donation found");
            foreach ($jobsForThisDonation as $job) {
                $job[1]->nack(new \RuntimeException('Donation not found'));
            }
            return;
        }

        $this->logger->info("Real Donation found");

        // below can be replaced with array_find when we upgrade to PHP 8.4
        $donationIsNew = array_reduce(
            array_map(static fn($job) => $job[0]->donationIsNew, $jobsForThisDonation),
            static fn(bool $left, bool $right) => $left || $right,
            false,
        );

        $jobsForThisDonationCount = count($jobsForThisDonation);

        try {
            $this->donationRepository->push($donation, $donationIsNew);
        } catch (\Throwable $exception) {
            // getId() works on proxy object, does not trigger lazy loading
            $campaginID = $donation->getCampaign()->getId();
            $this->logger->error(
                "Exception on attempt to push donation $donationUUID, for campaign # $campaginID \n" .
                "will nack $jobsForThisDonationCount jobs\n" .
                $exception
            );
            foreach ($jobsForThisDonation as $job) {
                $job[1]->nack($exception);
            }
            return;
        }

        foreach ($jobsForThisDonation as $job) {
            $this->logger->info("Acking $jobsForThisDonationCount jobs;");
            $job[1]->ack();
        }
    }

    /**
     * @psalm-suppress MoreSpecificImplementedParamType - this doesn't need to be LSP compliant with the interface
     * @param list<array{0: DonationStateUpdated, 1: Acknowledger}> $jobs
     */
    private function process(array $jobs): void
    {
        $jobCount = count($jobs);
        $this->logger->info("DSH attempting to process array of $jobCount jobs");
        $jobsByDonationUUID = [];

        foreach ($jobs as [$message, $ack]) {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation - necessary for PHPStorm
             * @var \MatchBot\Application\Messenger\DonationStateUpdated $message
             * @var Acknowledger $ack
             */
            $jobsByDonationUUID[$message->donationUUID][] = [$message, $ack];
        }

        foreach ($jobsByDonationUUID as $donationUUID => $jobsForThisDonation) {
            $this->pushOneDonation($donationUUID, $jobsForThisDonation);
        }
    }
}
