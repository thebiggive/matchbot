<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputInterface;
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
        private RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sends applicable donations to ClaimBot for HMRC Gift Aid claims');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $toClaim = $this->donationRepository->findReadyToClaimGiftAid();

        foreach ($toClaim as $donation) {
            $stamps = [
                new BusNameStamp('claimbot.donation.claim'),
                new TransportMessageIdStamp("claimbot.donation.claim.{$donation->getUuid()}"),
            ];
            $this->bus->dispatch(new Envelope($donation->toClaimBotModel(), $stamps));
        }

        $numberSent = count($toClaim);
        $output->writeln("Submitted $numberSent donations to the ClaimBot queue");

        return 0;
    }
}
