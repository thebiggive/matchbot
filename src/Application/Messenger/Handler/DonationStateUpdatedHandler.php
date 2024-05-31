<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

class DonationStateUpdatedHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(private DonationRepository $donationRepository)
    {
    }

    public function __invoke(DonationStateUpdated $donationStateUpdated, Acknowledger $ack = null)
    {
        return $this->handle($donationStateUpdated, $ack);
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
            $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

            if ($donation === null) {
                foreach ($jobsForThisDonation as $job) {
                    $job[1]->nack(new \RuntimeException('Donation not found'));
                }
                continue;
            }

            $donationIsNew = array_reduce(
                array_map(static fn ($job) => $job[0]->donationIsNew, $jobsForThisDonation),
                static fn(bool $left, bool $right) => $left || $right,
                false,
            );

            $this->donationRepository->push($donation, $donationIsNew);

            foreach ($jobsForThisDonation as $job) {
                $job[1]->ack();
            }
        }
    }
}
