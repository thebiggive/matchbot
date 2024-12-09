<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Send applicable donations to ClaimBot for HMRC Gift Aid claims.
 */
class ClaimGiftAid extends LockingCommand
{
    protected static $defaultName = 'matchbot:claim-gift-aid';

    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sends applicable donations to ClaimBot for HMRC Gift Aid claims');
        $this->addOption(
            'with-resends',
            null,
            InputOption::VALUE_NONE,
            'Tells the command to send donations again, even if they were queued before. Non-Production only',
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $toClaim = $this->donationRepository->findReadyToClaimGiftAid(
            !empty($input->getOption('with-resends')),
        );

        if (count($toClaim) > 0) {
            foreach ($toClaim as $donation) {
                $stamps = [
                    new BusNameStamp('claimbot.donation.claim'),
                    new TransportMessageIdStamp("claimbot.donation.claim.{$donation->getUuid()}"),
                ];
                $this->bus->dispatch(new Envelope($donation->toClaimBotModel(), $stamps));

                $donation->setTbgGiftAidRequestQueuedAt(new \DateTime());
                $this->entityManager->persist($donation);
            }

            $this->entityManager->flush();
        }

        $numberSent = count($toClaim);
        $output->writeln("Submitted $numberSent donations to the ClaimBot queue");

        return 0;
    }
}
