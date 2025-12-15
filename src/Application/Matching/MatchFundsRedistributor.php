<?php

namespace MatchBot\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/*
 * // this
 */
class MatchFundsRedistributor
{
    public function __construct(
        private Allocator $allocator,
        private ChatterInterface $chatter,
        private DonationRepository $donationRepository,
        private \DateTimeImmutable $now,
        private CampaignFundingRepository $campaignFundingRepository,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private RoutableMessageBus $bus,
//        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Redistribute match funding allocations where possible, from lower to higher priority
     * match fund pots.
     *
     * @return array{0: int, 1: int} Number of donations checked and amended respectively
     *
     * @throws TerminalLockException
     * @throws \DateInvalidOperationException
     */
    public function redistributeMatchFunds(\Closure $simulatedParallelProcess): array
    {
        $donationsToCheck = $this->donationRepository->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            campaignsClosedBefore: $this->now,
            // Since very long campaigns usually only have one funding type, it's currently unlikely
            // that the combination of minimum & maximum dates will stop funds being redistributed when
            // we'd like them to.
            donationsCollectedAfter: $this->now->sub(new \DateInterval('P8W')),
        );

        $donationsAmended = 0;
        foreach ($donationsToCheck as $donation) {
            $highestAllocationOrderUsedForDonation = 0;
            foreach ($donation->getFundingWithdrawals() as $withdrawal) {
                if ($withdrawal->isReversed()) {
                    echo "skipping withdrawl as is reversed\n";
                    continue;
                }
                if ($withdrawal->getAmount() < '0.0') {
                    continue;
                }

                $highestAllocationOrderUsedForDonation = max(
                    $highestAllocationOrderUsedForDonation,
                    $withdrawal->getCampaignFunding()->getAllocationOrder(),
                );
            }

//            $lock = $this->lockFactory->createLock(Confirm::donationConfirmLockKey($donation), autoRelease: true);
//            $lock->acquire(blocking: false);
            $fundings = $this->campaignFundingRepository->getAvailableFundings($donation->getCampaign());

            $fundingsAllowForRedistribution = false;
            foreach ($fundings as $funding) {
                if ($funding->getAllocationOrder() >= $highestAllocationOrderUsedForDonation) {
                    continue;
                }

                // If funding available is zero (or unexpectedly negative), it can't be used. Others maybe can,
                // so `continue` to check the next one.
                if (bccomp($funding->getAmountAvailable(), '0', 2) <= 0) {
                    continue;
                }

                $fundingsAllowForRedistribution = true;
                break; // Reallocation can occur regardless of whether one fund is involved, or many.
            }

            if (!$fundingsAllowForRedistribution) {
                continue;
            }

            $amountMatchedBeforeRedistribution = $donation->getFundingWithdrawalTotal();

            // Technically another donation could be allocated funds in between these next two lines, so we aim to run
            // this command only at quiet traffic times and also now only against donations to campaigns which
            // have closed. If we ever relax the latter condition, the worst case scenario is that we
            // inaccurately tell two donors they received matching. We log an error if this happens so we can
            // take action.

            /** @psalm-suppress InternalMethod */
            $this->allocator->releaseMatchFunds($donation);
            $simulatedParallelProcess();
            $amountMatchedAfterRedistribution = $this->allocator->allocateMatchFunds($donation);
//            $lock->release();

            // If the new allocation is less, log an error but still count the donation and continue with the loop.
            // We don't expect to actually see this happen as we now intend to run the script only for closed campaigns.
            if (bccomp($amountMatchedAfterRedistribution, $amountMatchedBeforeRedistribution, 2) === -1) {
                $this->logger->error(sprintf(
                    'Donation %s had redistributed match funds reduced from %s to %s (%s)',
                    $donation->getUuid(),
                    $amountMatchedBeforeRedistribution,
                    $amountMatchedAfterRedistribution,
                    $donation->currency()->isoCode(),
                ));
            }

            $this->entityManager->flush();
            $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
            $donationsAmended++;
        }

        $numberChecked = count($donationsToCheck);

        if ($donationsAmended > 0) {
            $this->sendSlackSummary($numberChecked, $donationsAmended);
        }

        return [$numberChecked, $donationsAmended];
    }

    private function sendSlackSummary(?int $numberChecked, int $donationsAmended): void
    {
        $env = getenv('APP_ENV');
        Assertion::string($env);

        $summary = "Checked $numberChecked donations and redistributed matching for $donationsAmended";
        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock(sprintf(
                '[%s] %s',
                $env,
                'Funds redistributed',
            ))))
            ->block((new SlackSectionBlock())->text($summary));
        $chatMessage = new ChatMessage('Funds redistribution');
        $chatMessage->options($options);

        $this->chatter->send($chatMessage);
    }
}
