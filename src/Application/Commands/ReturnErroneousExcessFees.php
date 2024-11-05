<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Assertion;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\Fees\Fees;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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
                $output->writeln("Donation {$donation->getUuid()} has already had fee refunded");
                continue;
            }

            $tipApplicationFeeOffsetPence = 0;
            // Currently we only support full refunds which update the overall status, and
            // tip refunds which don't change status but nonetheless set the refund timestamp.
            // So this `if` clause is a good early indicator that we might have a tip refund
            // to consider.
            if ($donation->getDonationStatus() === DonationStatus::Paid && $donation->hasRefund()) {
                // Stripe PI metadata has the specific original tip as we don't clear that.
                $paymentIntent = $this->stripeClient->paymentIntents->retrieve($donation->getTransactionId());
                $tipAmountInMetadata = $paymentIntent->metadata['tipAmount'] ?? '0';
                \assert(is_string($tipAmountInMetadata) && is_numeric($tipAmountInMetadata));
                $tipApplicationFeeOffsetPence = (int) bcmul('100', $tipAmountInMetadata, 2);
            }

            $feeDifferencePence = $fee->amount -
                $tipApplicationFeeOffsetPence -
                $donation->getAmountToDeductFractional();

            if (
                $tipApplicationFeeOffsetPence === 0 &&
                $donation->getCharityFee() !== $this->getCorrectFees($donation, $charge)->coreFee
            ) {
                $this->logger->error("Donation {$donation->getUuid()} has a different fee than expected");
                continue;
            }

            $countOfDonationsToChange++;
            $sumOfFeeDifferencePounds = bcadd($sumOfFeeDifferencePounds, (string) ($feeDifferencePence / 100), 2);

            if ($mode === 'fix') {
                $this->stripeClient->applicationFees->createRefund($feeId, [
                    'amount' => $feeDifferencePence,
                    'metadata' => ['reason' => 'MAT-383 erroneous fee correction'],
                ]);
                $output->writeln("Refunded {$feeDifferencePence} pence for {$donation->getUuid()}");

                $this->stripeClient->paymentIntents->update(
                    $donation->getTransactionId(),
                    [
                        'metadata' => [
                            'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                            'stripeFeeRechargeNet' => $donation->getCharityFee(),
                            'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
                        ]
                    ]
                );
            } else {
                $output->writeln("Would refund {$feeDifferencePence} pence for {$donation->getUuid()}");
            }
        }

        $output->writeln(sprintf(
            "Checked %d donations, found %d with differences, %d safe to correct, totalling %s pounds",
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

        $cardBrand = CardBrand::from($card->brand);
        $cardCountry = Country::fromAlpha2OrNull($card->country);

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
