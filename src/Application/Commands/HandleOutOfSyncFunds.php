<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
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
    protected bool $outOfSyncFundFound = false;
    protected static $defaultName = 'matchbot:handle-out-of-sync-funds';

    public function __construct(
        private CampaignFundingRepository $campaignFundingRepository,
        private EntityManagerInterface $entityManager,
        private FundingWithdrawalRepository $fundingWithdrawalRepository,
        private Matching\Adapter $matchingAdapter
    ) {
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

    /**
     * @psalm-suppress PossiblyUnusedReturnValue - return value is used by
     * \MatchBot\Application\Commands\Command::execute . Not sure why Psalm thinks its unused.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getArgument('mode');
        if (!in_array($mode, ['check', 'fix', '2024-06-05-patch'], true)) {
            $output->writeln('Please set the mode to "check" or "fix" or "2024-06-05-patch"');
            return 1;
        }

        $excludedFundingIds = [];
        if ($excludeJson = getenv('KNOWN_OVERMATCHED_FUNDING_IDS')) {
            $excludedFundingIds = json_decode($excludeJson, true, 512, JSON_THROW_ON_ERROR);
        }

        $numFundingsCorrect = 0;
        $numFundingsOvermatched = 0;
        $numFundingsUndermatched = 0;

        $problemIds = [];
        /** @var CampaignFunding[] $fundings */
        if ($mode === '2024-06-05-patch') {
            $problemIds = [29822];
            $output->writeln('Running in 2024-06-05-patch mode');
            // https://stackoverflow.com/a/52427915/2803757
            $fundings = $this->campaignFundingRepository->findBy(['id' => $problemIds]);
        } else {
            $fundings = $this->campaignFundingRepository->findAll();
        }
        $numFundings = count($fundings);

        foreach ($fundings as $funding) {
            if (in_array($funding->getId(), $excludedFundingIds, true)) {
                continue;
            }

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

                if ($mode === '2024-06-05-patch' && in_array($funding->getId(), $problemIds, true)) {
                    // Over match expected to be £300 but safest to drive it from live data and also assert.
                    Assertion::eq(bccomp($overmatchAmount, '300.00', 2), 0);

                    // This adapter fn already modifies the Doctrine $funding too, via `setAmountAvailable()`,
                    // just without flushing.
                    $newTotal = $this->matchingAdapter->subtractAmountWithoutSavingToDB($funding, $overmatchAmount);
                    $output->writeln(
                        "PATCH: Released {$overmatchAmount} to funding ID {$funding->getId()}. New total: $newTotal"
                    );

                    // Funding withdrawals were already correct. `$funding` available balance change is handled
                    // by matching adapter above. Just need to persist the change.
                    $this->entityManager->persist($funding);
                    $this->entityManager->flush();

                    $output->writeln("PATCH: Persisted changes to funding ID {$funding->getId()}");
                }

                continue;
            }

            // If the sum of FundingWithdrawals is smaller, add to an under-matched log count. If in
            // fix mode, restore any difference to Funds starting with the highest allocationOrder,
            // until the totals match.
            $numFundingsUndermatched++;

            $undermatchAmount = bcsub($campaignFundingAllocated, $fundingWithdrawalTotal, 2);

            $output->writeln("Funding {$funding->getId()} is under-matched by $undermatchAmount. $details");

            if ($mode === 'fix') {
                $newTotal = $this->matchingAdapter->addAmount($funding, $undermatchAmount);

                $output->writeln("Released {$undermatchAmount} to funding ID {$funding->getId()}");
                $output->writeln("New fund total for funding ID {$funding->getId()}: $newTotal");
            }
        }

        $output->writeln(
            "Checked $numFundings fundings. Found $numFundingsCorrect with correct allocations, " .
            "$numFundingsOvermatched over-matched and $numFundingsUndermatched under-matched"
        );

        if ($numFundingsOvermatched > 0 || $numFundingsUndermatched > 0) {
            $this->outOfSyncFundFound = true;
        }

        return 0;
    }
}
