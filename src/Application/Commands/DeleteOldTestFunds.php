<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:delete-old-test-funds',
    description: 'In regression environment only, removes Funds & associated data older than 1st of last month'
)]
class DeleteOldTestFunds extends LockingCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FundRepository $fundRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (Environment::current() !== Environment::Regression) {
            return 0;
        }

        $firstOfLastMonth = new \DateTimeImmutable('first day of last month');
        $pledges = $this->fundRepository->findOldTestPledges($firstOfLastMonth);
        foreach ($pledges as $pledge) {
            foreach ($pledge->getCampaignFundings() as $funding) {
                $this->entityManager->remove($funding); // Also removes FundingWithdrawals.
            }
            $this->entityManager->remove($pledge);
            $output->writeln(sprintf('Deleting old test pledge %d with SF ID %s', $pledge->getId() ?? -1, $pledge->getSalesforceId() ?? '?'));
        }

        $this->entityManager->flush();
        $output->writeln(sprintf('Deleted %d old test pledges', count($pledges)));

        return 0;
    }
}
