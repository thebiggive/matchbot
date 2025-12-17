<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

#[AsCommand(
    name: 'matchbot:scheduled-out-of-sync-funds-check',
    description: "For running via a cron job. Checks for out of sync funds but doesn't " .
        "attempt to fix them. Sends output to Slack"
)]
class ScheduledOutOfSyncFundsCheck extends HandleOutOfSyncFunds
{
    public function __construct(
        CampaignFundingRepository $campaignFundingRepository,
        EntityManagerInterface $entityManager,
        FundingWithdrawalRepository $fundingWithdrawalRepository,
        Matching\Adapter $matchingAdapter,
        DonationRepository $donationRepository,
        private ChatterInterface $chatter,
    ) {
        parent::__construct($campaignFundingRepository, $entityManager, $fundingWithdrawalRepository, $matchingAdapter, $donationRepository);
    }

    #[\Override]
    protected function configure(): void
    {
        // Don't call parent which would add the `mode` argument.
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $bufferedOutput = new BufferedOutput();

        $arrayInput = new ArrayInput(
            ['mode' => 'check'],
            new InputDefinition([new InputArgument('mode', InputArgument::REQUIRED)]),
        );

        parent::doExecute($arrayInput, $bufferedOutput);

        $chatMessage = new ChatMessage('Out of sync funds check');
        $message = 'Out of sync funds check completed' .
            ($this->outOfSyncFundFound ? " OUT OF SYNC FUNDS DETECTED" : " no out of sync funds detected");
        $output->writeln($message);
        if ($this->outOfSyncFundFound) {
            $env = getenv('APP_ENV');
            \assert(is_string($env));
            $options = (new SlackOptions())
                ->block((new SlackHeaderBlock(sprintf(
                    '[%s] %s',
                    $env,
                    $message,
                ))))
                ->block((new SlackSectionBlock())->text($bufferedOutput->fetch()));
            $chatMessage->options($options);

            $this->chatter->send($chatMessage);
        }

        return 0;
    }
}
