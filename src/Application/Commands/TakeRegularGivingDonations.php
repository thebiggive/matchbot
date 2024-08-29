<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Brick\DateTime\Instant;
use Doctrine\ORM\EntityManager;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\MandateService;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:take-regular-giving-donations',
    description: "Takes money from donors that they have given us advance permission to take.",
)]
class TakeRegularGivingDonations extends LockingCommand
{
    /** @psalm-suppress PossiblyUnusedMethod - called by PHP-DI */
    public function __construct(
        private \DateTimeImmutable $now,
        private RegularGivingMandateRepository $mandateRepository,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private MandateService $mandateService,
        private EntityManager $em,
    ) {
        parent::__construct();
    }
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->createNewDonationsAccordingToRegularGivingMandates();
        $this->confirmPreCreatedDonationsThatHaveReachedPaymentDate($output);

        return 0;
    }

    private function createNewDonationsAccordingToRegularGivingMandates(): void
    {
        /* we want any mandates that are:
            - Currently active
            - have "donations created up to" null or in the past.
        */
        $mandates = $this->mandateRepository->findMandatesWithDonationsToCreateOn($this->now, limit: 20);

        foreach ($mandates as [$mandate]) {
            $this->makeDonationForMandate($mandate);
            $this->em->flush();
        }
    }

    private function confirmPreCreatedDonationsThatHaveReachedPaymentDate(OutputInterface $output): void
    {
        /* Still to do to improve this before launch:
            - deal with possible "The parameter application_fee_amount cannot be updated on a PaymentIntent after a
              capture has already been made." error

            - Record unsuccessful payment attempts to limit number or time extent of retries
            - Stop collection if the related regular giving mandate has been cancelled
            - Send metadata to stripe so to identify the payment as regular giving when we view it there.
            - Handle exceptions and continue to next donation, e.g. if donation does not have customer ID, or there
              is no donor account in our db for that ID, or they do not have a payment method on file for this purpose.
            - Ensure we don't send emails that are meant for confirmation of on-session donations
            - Probably other things.
        */
        $donations = $this->donationRepository->findPreAuthorizedDonationsReadyToConfirm($this->now, limit:20);

        foreach ($donations as $donation) {
            $oldStatus = $donation->getDonationStatus();
            $output->writeln("processing donation $donation");
            $this->donationService->confirmPreAuthorized($donation);
            $output->writeln(
                "Donation {$donation->getUuid()} went from " .
                "{$oldStatus->name} to {$donation->getDonationStatus()->name}"
            );
        }

        $this->em->flush();
    }

    private function makeDonationForMandate(RegularGivingMandate $mandate): void
    {
        $this->mandateService->makeNextDonationForMandate($mandate);
        // todo - create donation and persist.
        // Requires adding a couple of new properties to the donation class & schema
        // - mandate ID & mandate sequence number. DB should ensure the combination is unique.
        // May need to adjust the DQL used to fetch the mandates so we also fetch the maximum existing sequence number,
        // or the last donation of each mandate so we know which one we're creating.

        // Also have to think about what to do if we need to create more than one. Shouldn't ever happen in prod as
        // will run this script daily and only need to create donations monthly, but probably worth dealing with in case
        // and for dev environments.

        // may want to write this in a service class instead of directly here so that it's more testable and
        // can potentially be invoked via HTTP for testing.
    }
}
