<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Aws\CloudWatch\CloudWatchClient;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send CloudWatch Metrics values of:
 * * overall completion rate of donations from ~16 minutes old thru ~1 minutes old, if 20+
 *   such donations started in that time
 * * donations created (in previous full minute before task start)
 * * donations collected (in previous full minute before task start)
 */
#[AsCommand(
    name: 'matchbot:send-statistics',
    description: 'Sends CloudWatch headline figures on donation initiation and collection'
)]
class SendStatistics extends LockingCommand
{
    public function __construct(
        private CloudWatchClient $cloudWatchClient,
        private DonationRepository $donationRepository
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $time = time();
        $startOfThisMinute = new \DateTimeImmutable('@' . ($time - ($time % 60)));
        $completionRate = $this->makeMetric(
            'CompletionRate',
            $this->donationRepository->getRecentHighVolumeCompletionRatio($startOfThisMinute),
            $startOfThisMinute,
        );
        $donationsCreated = $this->makeMetric(
            'DonationsCreated',
            $this->donationRepository->countDonationsJustCreated($startOfThisMinute),
            $startOfThisMinute,
        );
        $donationsCollected = $this->makeMetric(
            'DonationsCollected',
            $this->donationRepository->countDonationsJustCollected($startOfThisMinute),
            $startOfThisMinute,
        );
        $notNullMetrics = [$donationsCreated, $donationsCollected];
        if (null !== $completionRate['Value']) {
            $notNullMetrics[] = $completionRate;
        }

        $this->cloudWatchClient->putMetricData([
            'Namespace' => 'TbgMatchBot',
            'MetricData' => $notNullMetrics,
        ]);

        $count = count($notNullMetrics);
        $output->writeln("Sent $count metrics to CloudWatch");

        return 0;
    }

    /**
     * Value in default 'Unit' mode accepts doubles, so we leave this with the default for both float
     * & int values.
     */
    private function makeMetric(string $name, float | int | null $value, \DateTimeImmutable $timestamp): array
    {
        $appEnv = getenv('APP_ENV');
        \assert(is_string($appEnv)); // crash if somehow missing.
        return [
            'MetricName' => "tbg-$appEnv-$name",
            'Value' => $value,
            'Timestamp' => $timestamp,
        ];
    }
}
