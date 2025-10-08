<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * @see MatchFundsRedistributor which has some of the core logic in common.
 */
#[AsCommand(
    name: 'matchbot:patch-funds-8-oct',
    description: 'Fix some incorrect fundings',
)]
class PatchFunds8Oct extends Command
{
    public function __construct(
        private Allocator $allocator,
        private CampaignRepository $campaignRepository,
        private CampaignFundingRepository $campaignFundingRepository,
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private RoutableMessageBus $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'mode',
            InputArgument::REQUIRED,
            '"check" to print status information only or "fix" to attempt to restore over-allocated funds.'
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getArgument('mode');
        if (!in_array($mode, ['check', 'fix'], true)) {
            $output->writeln('Please set the mode to "check" or "fix"');
            return 1;
        }

        $campaigns = $this->getCampaignsAffected();
        $output->writeln('Campaigns affected: ' . count($campaigns));

        foreach ($campaigns as $campaign) {
            $campaignId = $campaign->getId() ?? throw new \Exception('campaign id missing');
            $donations = $this->donationRepository->findWithBigGiveWgmf25Matching($campaign);
            $output->writeln("Campaign $campaignId - Donations affected: " . count($donations));

            foreach ($donations as $donation) {
                if ($mode === 'check') {
                    $output->writeln('Donation ' . ($donation->getUuid()->toString()) . ' matching would be moved');
                    continue;
                }

                $this->switchMatchingToCorrectFunding($donation);
                $output->writeln('Donation ' . ($donation->getUuid()->toString()) . ' matching moved');
            }

            if ($mode === 'check') {
                $output->writeln("Campaign $campaignId processed - run in 'fix' mode to zero Big Give WGMF25 funding");
                continue;
            }

            $this->campaignFundingRepository->zeroBigGiveWgmf25Funding($campaign);
            $output->writeln("Campaign $campaignId processed + Big Give WGMF25 funding zeroed");
        }

        return Command::SUCCESS;
    }

    /**
     * @return Campaign[]
     */
    private function getCampaignsAffected(): array
    {
        // Raw pMA export
        $Campaign = [
            ['id' => '9629','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9641','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS000009MZDGYA4'],
            ['id' => '9649','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9671','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS000009MZDGYA4'],
            ['id' => '9676','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9678','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9692','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9708','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS000009MZDGYA4'],
            ['id' => '9725','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9729','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9731','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9732','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9735','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9753','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9763','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9767','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9768','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS000009MZDGYA4'],
            ['id' => '9777','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9778','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9779','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9786','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '9806','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqOyeYAF'],
            ['id' => '9814','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9824','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9838','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '9842','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000CqTzuYAF'],
            ['id' => '29091','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ'],
            ['id' => '29656','COUNT(DISTINCT cf.id)' => '2','GROUP_CONCAT(Fund.salesforceId)' => 'a09WS00000BDhATYA1,a09WS00000BlbDtYAJ']
        ];
        $campaignIds = array_column($Campaign, 'id');

        /** @var Campaign[] $campaigns */
        $campaigns = $this->campaignRepository->findBy(['id' => $campaignIds]);

        return $campaigns;
    }

    private function switchMatchingToCorrectFunding(Donation $donation): void
    {
        $originallyMatched = $donation->getFundingWithdrawalTotal();
        /** @psalm-suppress InternalMethod */
        $this->allocator->releaseMatchFunds($donation);
        $nowMatched = $this->allocator->allocateMatchFunds(donation: $donation, forceNotBigGive: true);

        if (bccomp($nowMatched, $originallyMatched, 2) === -1) {
            $this->logger->error(sprintf(
                'Donation %s had redistributed match funds reduced from %s to %s (%s)',
                $donation->getUuid(),
                $originallyMatched,
                $nowMatched,
                $donation->currency()->isoCode(),
            ));
        }

        $this->entityManager->flush();
        $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
    }
}
