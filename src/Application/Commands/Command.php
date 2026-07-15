<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

abstract class Command extends SymfonyCommand
{
    public const string CLI_OPTION_NOLOG = 'nolog';

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->start($input, $output);
        $return = $this->doExecute($input, $output);
        $this->finish($input, $output);

        return $return;
    }

    protected function start(InputInterface $input, OutputInterface $output): void
    {
        if ($this->noLog($input)) {
            return;
        }

        $output->writeln(($this->getName() ?? self::class) . ' starting!');
    }

    protected function finish(InputInterface $input, OutputInterface $output): void
    {
        if ($this->noLog($input)) {
            return;
        }

        $output->writeln(($this->getName() ?? self::class) . ' complete!');
    }

    private function noLog(InputInterface $input): bool
    {
        return $input->hasOption(self::CLI_OPTION_NOLOG) && $input->getOption(self::CLI_OPTION_NOLOG);
    }

    /**
     * Returns all commands available in the application. We could consider changing the implementation to
     * something that scans the directory.
     *
     * @return list<\Symfony\Component\Console\Command\Command>
     */
    public static function allCommands(ContainerInterface $c): array
    {
        $commands = array_map($c->get(...), [
            // Alphabetical list:
            CallFrequentTasks::class,
            CancelStaleDonationFundTips::class,
            ClaimGiftAid::class,
            ConsumeMessagesCommand::class,
            CreateFictionalData::class,
            DeleteOldTestFunds::class,
            ExpireMatchFunds::class,
            ExpirePendingMandates::class,
            HandleOutOfSyncFunds::class,
            MergeOpenApiDocs::class,
            PullIndividualCampaignFromSF::class,
            PullMetaCampaignFromSF::class,
            PushDailyFundTotals::class,
            PushDonations::class,
            RedistributeMatchFunds::class,
            ResetMatching::class,
            RetrospectivelyMatch::class,
            ScheduledOutOfSyncFundsCheck::class,
            SendStatistics::class,
            SetupTestMandate::class,
            TakeRegularGivingDonations::class,
            UpdateApproxCampaignStatus::class,
            UpdateCampaignDonationStats::class,
            UpdateCampaigns::class,
            WriteSchemaFile::class,
        ]);

        return $commands; // @mago-expect analysis:less-specific-nested-return-statement -- psalm knows the type is right, apparently Mago doesn't.
    }
}
