<?php

namespace MatchBot\Application\Messenger\Handler;

use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Environment;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Application\Messenger\DonationMatchingShouldBeChecked;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Deal with donation re-check message jobs (@see DonationMatchingShouldBeChecked)
 * from @see RetrospectivelyMatch
 */
#[AsMessageHandler]
class DonationMatchCheckHandler
{
    private const int MAX_STAT_SECONDS = 60 * 60 * 24 * 7; // 1 week, much longer than any command expected to need.

    public function __construct(
        private readonly Allocator $allocator,
        private readonly ChatterInterface $chatter,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $environment,
        private readonly LoggerInterface $logger,
        private readonly MatchFundsRedistributor $matchFundsRedistributor,
        private readonly \Redis $redis,
        private readonly RoutableMessageBus $bus,
        private readonly ContainerInterface $container, // apparently at the time this is constructed in tests
        // the container isn't ready to give us a donation or fund repo, so taking a ref to the container
        // instead and getting the repository inside __invoke etc.
    ) {
    }

    public function __invoke(DonationMatchingShouldBeChecked $message): void
    {
        $this->logger->debug('DonationMatchCheckHandler invoke; job ' . $message->retroMatchJobUuid . ', donations hash ' . $message->getMessageDeduplicationId());

        // Instantiate stats for this specific message of e.g. 10 donations. This invocation's
        // figures will be combined with those from other messages in Redis.
        $numWithMatchingAllocated = 0;
        /** @var int[] $campaignIds Fine to include duplicates; use of Redis set in `addToStats()` will efficiently discard them. */
        $campaignIds = [];
        $totalNewMatching = new Number('0.00');

        $donationRepository = $this->container->get(DonationRepository::class);
        $donations = $donationRepository->findByUuids($message->donationUuids);
        foreach ($donations as $donation) {
            $amountAllocatedNumericString = $this->allocator->allocateMatchFunds($donation);
            $amountAllocated = new Number($amountAllocatedNumericString);

            if ($amountAllocated > new Number('0.00')) {
                $this->entityManager->flush();
                $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
                $numWithMatchingAllocated++;
                $totalNewMatching = $totalNewMatching->add(num: $amountAllocated, scale: 2);
                $campaignIds[] = $donation->getCampaign()->getId();
            }
        }

        $this->addToStats(
            message: $message,
            campaignIdsWithAllocations: $campaignIds,
            numberAllocated: $numWithMatchingAllocated,
            penceAllocated: (int) (string) $totalNewMatching->mul(num: new Number(100), scale: 2),
        );

        if ($message->areFinalDonations) {
            $this->reportStats($message);
            $this->redistributeFunds();
            $this->syncFundUsage($message);
        }
    }

    /**
     * @param int[] $campaignIdsWithAllocations May include duplicates
     */
    private function addToStats(
        DonationMatchingShouldBeChecked $message,
        array $campaignIdsWithAllocations,
        int $numberAllocated,
        int $penceAllocated
    ): void {
        $this->redis->incrBy($this->keyForStat('numChecked', $message), count($message->donationUuids));
        // Below are sometimes no-ops, but should be fast and it's more complicated to expire() usefully if they're conditional.
        // incrBy() and sAdd() also initialise to 0 and empty set as necessary.
        $this->redis->incrBy($this->keyForStat('numAllocated', $message), $numberAllocated);
        $this->redis->incrBy($this->keyForStat('penceAllocated', $message), $penceAllocated);
        foreach ($campaignIdsWithAllocations as $campaignId) {
            $this->redis->sAdd($this->keyForStat('campaignIdsWithChanges', $message), $campaignId);
        }

        $this->expireStatsEventually($message);
    }

    private function reportStats(DonationMatchingShouldBeChecked $message): void
    {
        // Redis returns even what should internally be ints from get() as numeric strings.
        $numChecked = $this->redis->get($this->keyForStat('numChecked', $message));
        \assert(is_numeric($numChecked));
        $numWithMatchingAllocated = $this->redis->get($this->keyForStat('numAllocated', $message));
        \assert(is_numeric($numWithMatchingAllocated));
        $totalNewMatchingPence = $this->redis->get($this->keyForStat('penceAllocated', $message));
        \assert(is_numeric($totalNewMatchingPence));
        $totalNewMatching = new Number($totalNewMatchingPence)->div(num: new Number(100), scale: 2);
        $numDistinctCampaigns = $this->redis->scard($this->keyForStat('campaignIdsWithChanges', $message));
        \assert(is_int($numDistinctCampaigns));

        // @todo-multi-currency This message assumes GBP for now but the actual reallocation would use
        // Campaign/Donation currency if we were live with others.
        $summary = "Retrospectively matched $numWithMatchingAllocated of $numChecked donations. " .
            "£$totalNewMatching total new matching, across $numDistinctCampaigns campaigns.";
        $this->logger->info($summary);

        // If we did any new matching allocation, whether because of campaigns just closed or because
        // the command was run manually, send the results to Slack.
        if ($numDistinctCampaigns > 0 && $this->environment !== Environment::Test) {
            $chatMessage = new ChatMessage('Retrospective matching');
            $options = (new SlackOptions())
                ->block(new SlackHeaderBlock(sprintf('[%s] %s', $this->environment->name, 'Retrospective matching completed')))
                ->block(new SlackSectionBlock()->text($summary));
            $chatMessage->options($options);

            $this->chatter->send($chatMessage);
        }
    }

    /**
     * Moves any matches to higher priority funding if possible.
     */
    private function redistributeFunds(): void
    {
        [$numberChecked, $donationsAmended] = $this->matchFundsRedistributor->redistributeMatchFunds();
        $this->logger->info("Checked $numberChecked donations and redistributed matching for $donationsAmended");
    }

    private function syncFundUsage(DonationMatchingShouldBeChecked $message): void
    {
        $fundRepository = $this->container->get(FundRepository::class);
        // We want to include funds related to all campaigns in the scope of the retro match.
        $funds = $fundRepository->findForCampaignsClosedSince(
            closedBeforeDate: new \DateTimeImmutable('now'),
            closedSinceDate: $message->includesCampaignsClosedSince,
        );
        foreach ($funds as $fund) {
            // TODO maybe: could skip pledges to reduce load, until we are doing something with the info.
            $this->bus->dispatch(new Envelope(FundTotalUpdated::fromFund($fund)));
        }

        $fundSFIds = implode(', ', array_map(static fn(Fund $f) => $f->getSalesforceId(), $funds));
        $this->logger->info('Pushed fund totals to Salesforce for ' . count($funds) . ' funds: ' . $fundSFIds);
    }

    private function keyForStat(string $string, DonationMatchingShouldBeChecked $message): string
    {
        return 'retro-match-' . $message->retroMatchJobUuid . '-' . $string;
    }

    /**
     * Set a 1 week expiry, much longer than we expect to use them to report back, on all stats.
     */
    private function expireStatsEventually(DonationMatchingShouldBeChecked $message): void
    {
        foreach (['numChecked', 'numAllocated', 'penceAllocated', 'campaignIdsWithChanges'] as $type) {
            $this->redis->expire($this->keyForStat($type, $message), self::MAX_STAT_SECONDS);
        }
    }
}
