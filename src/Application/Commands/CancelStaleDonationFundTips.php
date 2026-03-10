<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Finds and cancels donation-funds type tips that have been uncollected for 14 days or longer. These
 * are likely to be from donors who either made a mistake when choosing a tip amount in the transfer form,
 * or selected a tip but have since forgotten or changed their mind.
 *
 * In any case, cancelling the tip donation will allow them to go through the form again and make a new choice to
 * tip us anything amount they prefer, or nothing.
 */
#[AsCommand(
    name: 'matchbot:cancel-stale-donation-fund-tips',
    description: 'Finds and cancels donation-funds type tips that have been uncollected for 14 days or longer.'
)]
class CancelStaleDonationFundTips extends LockingCommand
{
    public function __construct(
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private EntityManagerInterface $entityManager,
        private \DateTimeImmutable $now,
        private Environment $environment,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Cancel tips quicker outside prod to allow for easier testing:
        $twoWeeks = new \DateInterval('P14D');
        $tenMinutes = new \DateInterval('PT10M');

        $cancelationDelay = $this->environment == Environment::Production ? $twoWeeks : $tenMinutes;

        $staleDonationTipsUUIDS = $this->donationRepository->findStaleDonationFundsTips($this->now, $cancelationDelay);

        foreach ($staleDonationTipsUUIDS as $tipDonationUUID) {
            $this->entityManager->wrapInTransaction(function () use ($tipDonationUUID): void {
                $donation = $this->donationRepository->findAndLockOneByUUID($tipDonationUUID);
                Assertion::notNull($donation);

                $this->donationService->cancel($donation);
            });
            $output->writeln("Cancelled tip donation {$tipDonationUUID->toString()}");
        }

        $this->entityManager->flush();

        return 0;
    }
}
