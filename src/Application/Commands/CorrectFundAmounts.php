<?php

namespace MatchBot\Application\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\LessThanRequestedAllocatedException;
use MatchBot\Domain\CampaignFundingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:correct-fund-amounts',
    description: 'Add corrective CampaignFunding adjustments for issue MAT-480'
)]
class CorrectFundAmounts extends LockingCommand
{
    public function __construct(
        private Adapter $adapter,
        private CampaignFundingRepository $campaignFundingRepository,
        private LoggerInterface $logger,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $alreadyRun =
            $this->connection->fetchOne('SELECT count(*) from EmailVerificationToken where randomCode = \'d0b08f34-26ad-11f1-9dc7-fc5cee98dc66\'') == 1;

        if ($alreadyRun) {
            $output->writeln("Correct fund amounts already run, not re-running");
            return 0;
        }

        // Dummy email verification token just to work as flag to mark that this has already run and stop it running twice.
        $this->connection->executeQuery(
            'INSERT INTO EmailVerificationToken (createdAt, emailAddress, randomCode) VALUES (NOW(), \'info@biggive.org\', \'d0b08f34-26ad-11f1-9dc7-fc5cee98dc66\')'
        );

        $amountsToSubtract = [
            49320 => '15.00',
            49337 => '10.00',
            49367 => '1.00',
            49811 => '10.00',
            50218 => '10.00',
            50223 => '100.00',
            50228 => '20.00',
            50262 => '70.00',
        ];

        foreach ($amountsToSubtract as $campaignFundingId => $amount) {
            $campaignFunding = $this->campaignFundingRepository->find($campaignFundingId);
            if ($campaignFunding === null) {
                continue;
            }

            try {
                $this->adapter->subtractAmount(
                    funding: $campaignFunding,
                    amount: $amount,
                    donationId: null,
                    extraComment: 'MAT-480-correction'
                );
                $this->entityManager->flush();
                $this->logger->info("Subtracted $amount from funding $campaignFundingId");
            } catch (LessThanRequestedAllocatedException $e) {
                $this->logger->error("Got LessThanRequestedAllocatedException trying to subtract from CampaignFunding $campaignFundingId: " . $e->getMessage());
            }
        }

        return 0;
    }
}
