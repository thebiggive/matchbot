<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Messenger\DonationMatchingShouldBeChecked;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

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
    missed due to pending reservations, refunds etc.',
)]
class RetrospectivelyMatch extends LockingCommand
{
    public function __construct(
        private DonationRepository $donationRepository,
        private RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'days-back',
            InputArgument::OPTIONAL,
            'Number of days back to look for donations that could be matched.',
        );
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $recentlyClosedMode = !is_numeric($input->getArgument('days-back'));
        // Default is 1 hour before exec started.
        $sinceDate = new \DateTimeImmutable('now')->sub(new \DateInterval('PT1H'));

        if ($recentlyClosedMode) {
            // Default mode is now to auto match for campaigns that *just* closed.
            $output->writeln('Automatically evaluating campaigns which closed in the past hour');
            $toCheckForMatching = $this->donationRepository
                ->findNotFullyMatchedToCampaignsWhichClosedSince($sinceDate);
        } else {
            // Allow + round non-whole day count.
            $daysBack = round((float) $input->getArgument('days-back')); // @phpstan-ignore cast.double
            $output->writeln("Looking at past {$daysBack} days' donations");
            $sinceDate = new \DateTimeImmutable('now')->sub(new \DateInterval("P{$daysBack}D"));
            $toCheckForMatching = $this->donationRepository
                ->findRecentNotFullyMatchedToMatchCampaigns($sinceDate);
        }

        $jobId = Uuid::uuid4();
        $uuidsToCheck = array_map(static fn(Donation $d) => $d->getUuid()->toString(), $toCheckForMatching);
        $uuidChunks = array_chunk($uuidsToCheck, 10);
        foreach ($uuidChunks as $chunkIndex => $uuids) {
            $this->bus->dispatch(new Envelope(new DonationMatchingShouldBeChecked(
                donationUuids: $uuids,
                retroMatchJobUuid: $jobId->toString(),
                includesCampaignsClosedSince: $sinceDate,
                areFinalDonations: $chunkIndex === ( count($uuidChunks) - 1 ),
            )));
        }

        return 0;
    }
}
