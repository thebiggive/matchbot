<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Find complete donations to matched campaigns with less matching than their full value, from the past N days
 * if specified, and allocate any newly-available match funds to them.
 *
 * If not argument (number of days) is given, campaigns which closed within the last hour are checked
 * and all of their donations are eligible for matching.
 */
class RetrospectivelyMatch extends LockingCommand
{
    protected static $defaultName = 'matchbot:retrospectively-match';

    public function __construct(
        private DonationRepository $donationRepository,
        private ChatterInterface $chatter,
        private RoutableMessageBus $bus,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(
            "Allocates matching for just-closed campaigns' donations, or the " .
            "last N days' donations, if missed due to pending reservations, refunds etc."
        );
        $this->addArgument(
            'days-back',
            InputArgument::OPTIONAL,
            'Number of days back to look for donations that could be matched.'
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_numeric($input->getArgument('days-back'))) {
            // Default mode is now to auto match for campaigns that *just* closed.
            $output->writeln('Automatically evaluating campaigns which closed in the past hour');

            $oneHourAgo = (new DateTime('now'))->sub(new \DateInterval('PT1H'));
            $toCheckForMatching = $this->donationRepository
                ->findNotFullyMatchedToCampaignsWhichClosedSince($oneHourAgo);
        } else {
            // Allow + round non-whole day count.
            $daysBack = round((float) $input->getArgument('days-back'));
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
            $amountAllocated = $this->donationRepository->allocateMatchFunds($donation);

            if (bccomp($amountAllocated, '0.00', 2) === 1) {
                $this->entityManager->flush();
                $this->bus->dispatch(
                    new Envelope(
                        DonationStateUpdated::fromDonation($donation)
                    ),
                    [new DelayStamp(delay: 1_000 /*one second */)],
                );
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
            $options = (new SlackOptions())
                ->block((new SlackHeaderBlock(sprintf(
                    '[%s] %s',
                    getenv('APP_ENV'),
                    'Retrospective matching completed',
                ))))
                ->block((new SlackSectionBlock())->text($summary));
            $chatMessage->options($options);

            $this->chatter->send($chatMessage);
        }

        return 0;
    }
}
