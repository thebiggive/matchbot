<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * In rare cases, only after a process or infrastructure crash, it might be necessary to update
 * CampaignFundings' available amounts - and critically the authoritative amounts in Redis (or whatever
 * the live matching adapter is) - based on the total of donations' `FundingWithdrawal`s.
 *
 * This effectively reverses the normal source of authority for matching totals, treating the less-
 * realtime values as *more* accurate than the realtime ones, and should therefore only be run in
 * exceptional circumstances, where:
 *
 *  1. you have reason to believe the authoritative totals are wrong
 *  2. you understand why that has happened and are sure it won't happen again
 *  3. you expect donations to be at a low volume when you run this, reducing the risk of things
 *     getting back out of sync because of donations coming in while it runs
 *
 * Keep in mind that it is also normal while reservations are happening for funds to *briefly* diverge and show up
 * in the output of this command (this underscores the reason it's important to use the 'fix' mode sparingly!) Before
 * taking action based on a reported mismatch, run the command a second time and check the same funds and amounts
 * are listed as the first time.
 */
class HandleOutOfSyncFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:handle-out-of-sync-funds';

    private CampaignFundingRepository $campaignFundingRepository;
    private FundingWithdrawalRepository $fundingWithdrawalRepository;
    private Matching\Adapter $matchingAdapter;

    public function __construct(
        CampaignFundingRepository $campaignFundingRepository,
        FundingWithdrawalRepository $fundingWithdrawalRepository,
        Matching\Adapter $matchingAdapter
    ) {
        $this->campaignFundingRepository = $campaignFundingRepository;
        $this->fundingWithdrawalRepository = $fundingWithdrawalRepository;
        $this->matchingAdapter = $matchingAdapter;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription("Tries to match every fund's amount available to its FundingWithdrawals' total");
        $this->addArgument(
            'mode',
            InputArgument::REQUIRED,
            '"check" to print status information only or "fix" to attempt to restore over-allocated funds.'
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getArgument('mode');
        if (!in_array($mode, ['check', 'fix'], true)) {
            $output->writeln('Please set the mode to "check" or "fix"');
            return 1;
        }

        $numFundingsCorrect = 0;
        $numFundingsOvermatched = 0;
        $numFundingsUndermatched = 0;
        $fundings = $this->campaignFundingRepository->findAll();
        $numFundings = count($fundings);

        foreach ($fundings as $funding) {
            /** @var CampaignFunding $funding */

            // Amount allocated from the CampaignFunding
            $fundingAvailable = $this->matchingAdapter->getAmountAvailable($funding);
            $campaignFundingAllocated = bcsub($funding->getAmount(), $fundingAvailable, 2);

            // Get the sum of all FundingWithdrawals for donations, whether complete or active reservations.
            $fundingWithdrawalTotal = $this->fundingWithdrawalRepository->getWithdrawalsTotal($funding);

            $comparison = bccomp($campaignFundingAllocated, $fundingWithdrawalTotal, 2);

            if ($comparison === 0) {
                // If they match, add to the OK campaigns total
                $numFundingsCorrect++;
                continue;
            }

            // If the sum of FundingWithdrawals is larger, log and count the over-match. No action can safely auto-fix
            // this.
            $details = "Donation withdrawals $fundingWithdrawalTotal, funding allocations $campaignFundingAllocated";
            if ($comparison === -1) {
                $numFundingsOvermatched++;
                $overmatchAmount = bcsub($fundingWithdrawalTotal, $campaignFundingAllocated, 2);
                $output->writeln("Funding {$funding->getId()} is over-matched by $overmatchAmount. $details");
                continue;
            }

            // If the sum of FundingWithdrawals is smaller, add to an under-matched log count. If in
            // fix mode, restore any difference to Funds starting with the highest allocationOrder,
            // until the totals match.
            $numFundingsUndermatched++;

            $undermatchAmount = bcsub($campaignFundingAllocated, $fundingWithdrawalTotal, 2);

            $output->writeln("Funding {$funding->getId()} is under-matched by $undermatchAmount. $details");

            if ($mode === 'fix') {
                $newTotal = $this->matchingAdapter->runTransactionally(
                    function () use ($funding, $undermatchAmount) {
                        return $this->matchingAdapter->addAmount($funding, $undermatchAmount);
                    }
                );

                $output->writeln("Released {$undermatchAmount} to funding {$funding->getId()}");
                $output->writeln("New fund total for {$funding->getId()}: $newTotal");
            }
        }

        $output->writeln(
            "Checked $numFundings fundings. Found $numFundingsCorrect with correct allocations, " .
            "$numFundingsOvermatched over-matched and $numFundingsUndermatched under-matched."
        );

        return 0;
    }
}
