<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
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
    /**
     * @psalm-suppress PossiblyUnusedMethod - called by framework
     */
    public function __construct(
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private EntityManagerInterface $entityManager,
        private \DateTimeImmutable $now
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $staleDonationTips = $this->donationRepository->findStaleDonationFundsTips($this->now);

        foreach ($staleDonationTips as $tipDonation) {
            $this->donationService->cancel($tipDonation);
            $output->writeln("Cancelled tip donation {$tipDonation->getUuid()}");
        }

        $this->entityManager->flush();

        return 0;
    }
}
