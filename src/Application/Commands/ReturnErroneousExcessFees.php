<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\Fees\Fees;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Stripe\Card;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Temporary command to correct fees overcharged in error, so that charities receive an extra
 * payout covering the difference ASAP.
 */
#[AsCommand(
    name: 'matchbot:fix-fees',
    description: "Prints or fixes fees accidentally overcharged to some charities",
)]
class ReturnErroneousExcessFees extends LockingCommand
{
    public function __construct(
        private DonationRepository $donationRepository,
        private readonly StripeClient $stripeClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'mode',
            InputArgument::REQUIRED,
            '"check" to print status information only or "fix" to issue fee refunds.'
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getArgument('mode');
        if (!in_array($mode, ['check', 'fix'], true)) {
            $output->writeln('Please set the mode to "check" or "fix"');
            return 1;
        }

        $donations = $this->donationRepository->findWithFeePossiblyOverchaged();

        $countOfDonationsChecked = count($donations);
        $countOfDonationsWithDiscrepancy = 0;
        $countOfDonationsToChange = 0;

        /** @var numeric-string $sumOfFeeDifferencePounds */
        $sumOfFeeDifferencePounds = '0.00';

        foreach ($donations as $donation) {
            $chargeId = $donation->getChargeId();
            Assertion::notNull($chargeId, "Donation {$donation->getUuid()} has no charge ID");
            $charge = $this->stripeClient->charges->retrieve($chargeId);

            if (!$this->feeDiffers($donation, $charge) || $charge->status !== 'succeeded') {
                continue;
            }

            $countOfDonationsWithDiscrepancy++;
            $feeId = (string) $charge->application_fee;
            $fee = $this->stripeClient->applicationFees->retrieve($feeId);

            if ($fee->amount_refunded > 0) {
                // Might be because the donation itself was fully refunded and the fee reversed. Carry on.
                $output->writeln("Donation {$donation->getId()} has already had fee refunded");
                continue;
            }

            $feeDifferencePence = $fee->amount - $donation->getAmountToDeductFractional();

            if ($donation->getCharityFee() !== $this->getCorrectFees($donation, $charge)->coreFee) {
                $output->writeln("Donation {$donation->getId()} has a different fee than expected");
                continue;
            }

            $countOfDonationsToChange++;
            $sumOfFeeDifferencePounds = bcadd($sumOfFeeDifferencePounds, (string) ($feeDifferencePence / 100), 2);

            if ($mode === 'fix') {
                $this->stripeClient->applicationFees->createRefund($feeId, [
                    'amount' => $feeDifferencePence,
                    'metadata' => ['reason' => 'MAT-383 erroneous fee correction'],
                ]);
                $output->writeln("Refunded {$feeDifferencePence} for {$donation->getUuid()}");
            } else {
                $output->writeln("Would refund {$feeDifferencePence} for {$donation->getUuid()}");
            }
        }

        $output->writeln(sprintf(
            "Checked %d donations, found %d with differences, %d safe to correct, totalling %s",
            $countOfDonationsChecked,
            $countOfDonationsWithDiscrepancy,
            $countOfDonationsToChange,
            $sumOfFeeDifferencePounds,
        ));

        return 0;
    }

    /**
     * Whether the actually charged `application_fee_amount` differs from the database Donation amount to deduct.
     */
    private function feeDiffers(Donation $donation, \Stripe\Charge $charge): bool
    {
         return $charge->application_fee_amount !== $donation->getAmountToDeductFractional();
    }

    private function getCorrectFees(Donation $donation, \Stripe\Charge $charge): Fees
    {
        /**
         * @var array|Card|null $card
         */
        $card = $charge->payment_method_details?->toArray()['card'] ?? null;
        if (is_array($card)) {
            /** @var Card $card */
            $card = (object)$card;
        }

        if (!$card) {
            throw new \LogicException('Cannot continue with no card on charge');
        }

        $cardBrand = $card->brand;
        $cardCountry = $card->country;

        return Calculator::calculate(
            psp: 'stripe',
            cardBrand: $cardBrand,
            cardCountry: $cardCountry,
            amount: $donation->getAmount(),
            currencyCode: $donation->getCurrencyCode(),
            hasGiftAid: $donation->hasGiftAid() && $donation->hasTbgShouldProcessGiftAid()
        );
    }
}
