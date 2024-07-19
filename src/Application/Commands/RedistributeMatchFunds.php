<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * Redistribute match funding allocations where possible, from lower to higher priority match fund pots.
 */
class RedistributeMatchFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:redistribute-match-funds';

    public function __construct(
        private CampaignFundingRepository $campaignFundingRepository,
        private EntityManagerInterface $entityManager,
        private \DateTimeImmutable $now,
        private DonationRepository $donationRepository,
        private LoggerInterface $logger,
        private RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Moves match funding allocations from lower to higher priority funds where possible');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $donationsToCheck = $this->donationRepository->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            campaignsClosedBefore: $this->now,
            // Since very long campaigns usually only have one funding type, it's currently unlikely
            // that the combination of minimum & maximum dates will stop funds being redistributed when
            // we'd like them to.
            donationsCollectedAfter: $this->now->sub(new \DateInterval('P8W')),
        );

        $donationsAmended = 0;
        foreach ($donationsToCheck as $donation) {
            $highestAllocationOrderUsedForDonation = 0;
            foreach ($donation->getFundingWithdrawals() as $withdrawal) {
                $highestAllocationOrderUsedForDonation = max(
                    $highestAllocationOrderUsedForDonation,
                    $withdrawal->getCampaignFunding()->getAllocationOrder(),
                );
            }

            $fundings = $this->campaignFundingRepository->getAvailableFundings($donation->getCampaign());

            $fundingsAllowForRedistribution = false;
            foreach ($fundings as $funding) {
                if ($funding->getAllocationOrder() >= $highestAllocationOrderUsedForDonation) {
                    continue;
                }

                // If funding available is zero (or unexpectedly negative), it can't be used. Others maybe can,
                // so `continue` to check the next one.
                if (bccomp($funding->getAmountAvailable(), '0', 2) <= 0) {
                    continue;
                }

                $fundingsAllowForRedistribution = true;
                break; // Reallocation can occur regardless of whether one fund is involved, or many.
            }

            if (!$fundingsAllowForRedistribution) {
                continue;
            }

            $amountMatchedBeforeRedistribution = $donation->getFundingWithdrawalTotal();

            // Technically another donation could be allocated funds in between these two lines, so we aim to run
            // this command only at quiet traffic times and also now only against donations to campaigns which
            // have closed. If we ever relax the latter condition, the worst case scenario is that we
            // inaccurately tell two donors they received matching. We log an error if this happens so we can
            // take action.
            $this->donationRepository->releaseMatchFunds($donation);
            $amountMatchedAfterRedistribution = $this->donationRepository->allocateMatchFunds($donation);

            // If the new allocation is less, log an error but still count the donation and continue with the loop.
            // We don't expect to actually see this happen as we now intend to run the script only for closed campaigns.
            if (bccomp($amountMatchedAfterRedistribution, $amountMatchedBeforeRedistribution, 2) === -1) {
                $this->logger->error(sprintf(
                    'Donation %s had redistributed match funds reduced from %s to %s (%s)',
                    $donation->getUuid(),
                    $amountMatchedBeforeRedistribution,
                    $amountMatchedAfterRedistribution,
                    $donation->getCurrencyCode(),
                ));
            }

            $this->entityManager->flush();
            $this->bus->dispatch(new Envelope(DonationUpserted::fromDonation($donation)));
            $donationsAmended++;
        }

        $numberChecked = count($donationsToCheck);
        $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");

        return 0;
    }
}
