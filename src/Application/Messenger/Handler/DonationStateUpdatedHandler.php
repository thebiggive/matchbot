<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationStateUpdated;
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
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DonationStateUpdated $donationStateUpdated, Acknowledger $ack = null)
    {
        return $this->handle($donationStateUpdated, $ack);
    }

    /**
     * @param list<array{0: DonationStateUpdated, 1: Acknowledger}> $jobsForThisDonation
     */
    private function pushOneDonation(string $donationUUID, array $jobsForThisDonation): void
    {
        /** @psalm-suppress MixedPropertyFetch - allSatisfy isn't written with generics */
        Assertion::allSatisfy(
            $jobsForThisDonation,
            static fn(array $job) => $job[0]->donationUUID === $donationUUID
        );

        $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

        if ($donation === null) {
            foreach ($jobsForThisDonation as $job) {
                $job[1]->nack(new \RuntimeException('Donation not found'));
            }
            return;
        }

        // below can be replaced with array_find when we upgrade to PHP 8.4
        $donationIsNew = array_reduce(
            array_map(static fn($job) => $job[0]->donationIsNew, $jobsForThisDonation),
            static fn(bool $left, bool $right) => $left || $right,
            false,
        );

        try {
            $this->donationRepository->push($donation, $donationIsNew);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                '%s pushing donation: %s',
                get_class($exception),
                $exception->getMessage(),
            ));

            foreach ($jobsForThisDonation as $job) {
                $job[1]->nack($exception);
            }
            return;
        }

        foreach ($jobsForThisDonation as $job) {
            $job[1]->ack();
        }
    }

    /**
     * @psalm-suppress MoreSpecificImplementedParamType - this doesn't need to be LSP compliant with the interface
     * @param list<array{0: DonationStateUpdated, 1: Acknowledger}> $jobs
     */
    private function process(array $jobs): void
    {
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
