<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Redistribute match funding allocations where possible, from lower to higher priority match fund pots.
 */
class RedistributeMatchFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:redistribute-match-funds';

    public function __construct(
        private CampaignFundingRepository $campaignFundingRepository,
        private DonationRepository $donationRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Moves match funding allocations from lower to higher priority funds where possible');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // TODO Change the fixed lookback to 2 days, or parameter-ise it, once CC23 is tidied in Prod.
        $donationsToCheck = $this->donationRepository->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            new \DateTimeImmutable('-3 weeks')
        );

        $donationsAmended = 0;
        foreach ($donationsToCheck as $donation) {
            $highestAllocationOrderUsedForDonation = 0;
            foreach ($donation->getFundingWithdrawals() as $withdrawal) {
                $funding = $withdrawal->getCampaignFunding();
                if (!$funding) {
                    // Not entirely sure why this is nullable at all; we can't work on withdrawals without a funding.
                    continue;
                }

                $highestAllocationOrderUsedForDonation = max(
                    $highestAllocationOrderUsedForDonation,
                    $funding->getAllocationOrder(),
                );
            }

            $fundings = $this->campaignFundingRepository->getAvailableFundings($donation->getCampaign());

            $fundingsAllowForRedistribution = false;
            foreach ($fundings as $funding) {
                if ($funding->getAllocationOrder() <= $highestAllocationOrderUsedForDonation) {
                    continue;
                }

                $amountToAllocate = min($donation->getAmount(), $funding->getAmountAvailable());
                if ($amountToAllocate <= 0) {
                    continue;
                }

                $fundingsAllowForRedistribution = true;
                break;
            }

            if (!$fundingsAllowForRedistribution) {
                continue;
            }

            $this->donationRepository->releaseMatchFunds($donation);
            $this->donationRepository->allocateMatchFunds($donation);
            $donationsAmended++;
        }

        $numberChecked = count($donationsToCheck);
        $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");

        return 0;
    }
}
