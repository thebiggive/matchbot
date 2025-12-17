<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Find complete donations to matched campaigns with less matching than their full value, from the past N days
 * if specified, and allocate any newly-available match funds to them.
 *
 * Also does some follow up tasks that make sense after matching is updated:
 * 1. Redistributes any match funds to higher allocation order funds (e.g. from champion funds to pledges) if appropriate.
 * 2. If running without --days-back and campaigns just closed, pushes their funds' amounts used to Salesforce.
 *
 * @see PushDailyFundTotals which does similar to (2) but for all open campaigns, typically scheduled daily.
 *
 * If not argument (number of days) is given, campaigns which closed within the last hour are checked
 * and all of their donations are eligible for matching.
 */
#[AsCommand(
    name: 'matchbot:retrospectively-match',
    description: 'Allocates matching for just-closed campaigns\' donations, or the last N days\' donations, if
    missed due to pending reservations, refunds etc.'
)]
class RetrospectivelyMatch extends LockingCommand
{
    public function __construct(
        private Allocator $allocator,
        private DonationRepository $donationRepository,
        private FundRepository $fundRepository,
        private ChatterInterface $chatter,
        private RoutableMessageBus $bus,
        private EntityManagerInterface $entityManager,
        private MatchFundsRedistributor $matchFundsRedistributor,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'days-back',
            InputArgument::OPTIONAL,
            'Number of days back to look for donations that could be matched.'
        );
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $recentlyClosedMode = !is_numeric($input->getArgument('days-back'));
        $oneHourBeforeExecStarted = (new DateTime('now'))->sub(new \DateInterval('PT1H'));

        if ($recentlyClosedMode) {
            // Default mode is now to auto match for campaigns that *just* closed.
            $output->writeln('Automatically evaluating campaigns which closed in the past hour');
            $toCheckForMatching = $this->donationRepository
                ->findNotFullyMatchedToCampaignsWhichClosedSince($oneHourBeforeExecStarted);
        } else {
            // Allow + round non-whole day count.
            $daysBack = round((float) $input->getArgument('days-back')); // @phpstan-ignore cast.double
            $output->writeln("Looking at past $daysBack days' donations");
            $sinceDate = (new DateTime('now'))->sub(new \DateInterval("P{$daysBack}D"));
            $toCheckForMatching = $this->donationRepository
                ->findRecentNotFullyMatchedToMatchCampaigns($sinceDate);
        }

        $numChecked = count($toCheckForMatching);
        $distinctCampaignIds = [];
        $numWithMatchingAllocated = 0;
        $totalNewMatching = '0.00';

        foreach ($toCheckForMatching as $donation) {
            $amountAllocated = $this->allocator->allocateMatchFunds($donation);

            if (bccomp($amountAllocated, '0.00', 2) === 1) {
                $this->entityManager->flush();
                $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
                $numWithMatchingAllocated++;
                $totalNewMatching = bcadd($totalNewMatching, $amountAllocated, 2);

                if (!in_array($donation->getCampaign()->getId(), $distinctCampaignIds, true)) {
                    $distinctCampaignIds[] = $donation->getCampaign()->getId();
                }
            }
        }

        $numDistinctCampaigns = count($distinctCampaignIds);

        $summary = "Retrospectively matched $numWithMatchingAllocated of $numChecked donations. " .
            "£$totalNewMatching total new matching, across $numDistinctCampaigns campaigns.";
        $output->writeln($summary);

        // If we did any new matching allocation, whether because of campaigns just closed or because
        // the command was run manually, send the results to Slack.
        if ($numDistinctCampaigns > 0) {
            $chatMessage = new ChatMessage('Retrospective matching');
            $env = getenv('APP_ENV');
            Assertion::string($env);

            $options = (new SlackOptions())
                ->block((new SlackHeaderBlock(sprintf(
                    '[%s] %s',
                    $env,
                    'Retrospective matching completed',
                ))))
                ->block((new SlackSectionBlock())->text($summary));
            $chatMessage->options($options);

            $this->chatter->send($chatMessage);

            [$numberChecked, $donationsAmended] = $this->matchFundsRedistributor->redistributeMatchFunds();
            $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");
        }

        // Intentionally use the "stale" `$oneHourBeforeExecStarted` – we want to include funds related to all
        // campaigns processed above, even if the previous work took a long time.
        $funds = $this->fundRepository->findForCampaignsClosedSince(new DateTime('now'), $oneHourBeforeExecStarted);
        foreach ($funds as $fund) {
            // TODO maybe: could skip pledges to reduce load, until we are doing something with the info.
            $this->bus->dispatch(new Envelope(FundTotalUpdated::fromFund($fund)));
        }

        $fundSFIds = implode(', ', array_map(static fn(Fund $f) => $f->getSalesforceId(), $funds));
        $output->writeln('Pushed fund totals to Salesforce for ' . count($funds) . ' funds: ' . $fundSFIds);

        return 0;
    }
}
