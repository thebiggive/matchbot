<?php

namespace MatchBot\Application\Commands;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Infection\Console\IO;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Use to create a regular giving agreement for test purposes. Usage example:
 *
 * To create a regular giving mandate for the emergency campaign, with gift aid enabled, for
 * a donor with the given identity server UUID, stripe customer ID and payment method ID, to donate £500 each month
 * on the 20th day of the month, starting this month if today is <19th otherwise starting next month run:
 *
 * ./matchbot matchbot:setup-test-mandate --campaign Emergency --gift-aid
 *      --donor-uuid 72f1e368-65f3-11ef-878f-fb0a039f0650 --donor-stripeid cus_xyxzaxasfdef
*       --donor-pmid pm_1xyzxzyxzyxzyxzyxzyxzyxz 500 20
 *
 * This doesn't create the intitial donation, but it does pre-authorise the 2nd and 3rd donations.
 */
#[AsCommand(
    name: 'matchbot:setup-test-mandate',
    description: "For use in non-prod only. Sets up a new regular giving mandate in the DB so we can test processing",
)]
class SetupTestMandate extends LockingCommand
{
    private \DateTimeImmutable $now;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Environment $environment,
        private EntityManagerInterface $em,
        private CampaignRepository $campaignRepository,
        private DonorAccountRepository $donorAccountRepository,
        \DateTimeImmutable $now,
    ) {
        parent::__construct();
        $this->now = $now->setTimezone(new \DateTimeZone('Europe/London'));
    }

    public function configure(): void
    {
        $this->addOption('donor-uuid', 'du', InputOption::VALUE_REQUIRED, 'UUID of the donor in identity service');
        $this->addOption('donor-stripeid', 'ds', InputOption::VALUE_REQUIRED, 'id of the donor in stripe');
        $this->addOption(
            'donor-pmid',
            'dp',
            InputOption::VALUE_REQUIRED,
            'id of payment method in stripe'
        );

        $this->addOption('campaign', 'c', InputOption::VALUE_REQUIRED);
        $this->addOption('gift-aid', 'g', InputOption::VALUE_NONE);
        $this->addArgument('amount', InputArgument::OPTIONAL, 'Amount in pounds');
        $this->addArgument('day-of-month', InputArgument::OPTIONAL, 'Amount in pounds');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->environment == Environment::Production) {
            throw new \Exception("Not for use in production");
        }

        $io = new SymfonyStyle($input, $output);

        $donorId = PersonId::of((string) $input->getOption('donor-uuid'));
        $amount = (int)($input->getArgument('amount') ?? '1');
        $campaignName = (string) $input->getOption('campaign');

        $criteria = (new Criteria())->where(Criteria::expr()->contains('name', $campaignName));
        $campaign = $this->campaignRepository->matching($criteria)->first();

        if (!$campaign) {
            $io->error("No campaign found for {$campaignName}");
            return Command::FAILURE;
        }

        $charity = $campaign->getCharity();

        $dayOfMonth = DayOfMonth::of((int)($input->getArgument('day-of-month') ?? '1'));

        $mandate = new RegularGivingMandate(
            $donorId,
            Money::fromPoundsGBP($amount),
            Salesforce18Id::ofCampaign(
                $campaign->getSalesforceId() ?? throw new \Exception('Missing campaign sf ID')
            ),
            Salesforce18Id::ofCharity(
                $charity->getSalesforceId() ?? throw new \Exception('Missing charity sf ID')
            ),
            (bool)$input->getOption('gift-aid'),
            $dayOfMonth
        );
        $mandate->activate($this->now);

        $donorStripeId = (string)$input->getOption('donor-stripeid');

        $donor = $this->donorAccountRepository->findByStripeIdOrNull(StripeCustomerId::of($donorStripeId));
        if ($donor === null) {
            $donor = new DonorAccount(
                $donorId,
                EmailAddress::of('test@biggive.org'),
                DonorName::of('First Name', 'Last Name'),
                StripeCustomerId::of($donorStripeId)
            );
            $donor->setBillingCountryCode('GB');
            $donor->setBillingPostcode('SW1 1AA');
            $donor->setHomePostcode('SW1 1AA');
            $donor->setHomeAddressLine1('Home line 1');
            $this->em->persist($donor);
        }
        $donor->setRegularGivingPaymentMethod(StripePaymentMethodId::of((string) $input->getOption('donor-pmid')));


        $this->makePreAuthedDonations($mandate, $campaign, $donor);

        $this->em->persist($mandate);
        $this->em->flush();

        $io->writeln(
            "<fg=black;bg=green>" .
            "Created new regular giving mandate: #{$mandate->getId()} for {$campaignName} by {$charity->getName()}" .
            "</>"
        );

        return 0;
    }

    private function makePreAuthedDonations(
        RegularGivingMandate $mandate,
        Campaign $campaign,
        DonorAccount $donor,
    ): void {
        $paymentDay2ndDonation = $mandate->firstPaymentDayAfter($this->now);
        $paymentDay3rdDonation = $mandate->firstPaymentDayAfter($paymentDay2ndDonation);

        $this->preAuthorizeNewDonation($mandate, $campaign, $donor, $paymentDay2ndDonation);
        $this->preAuthorizeNewDonation($mandate, $campaign, $donor, $paymentDay3rdDonation);
    }


    public function preAuthorizeNewDonation(
        RegularGivingMandate $mandate,
        Campaign $campaign,
        DonorAccount $donor,
        \DateTimeImmutable $paymentDay,
    ): void {
        $donation = Donation::fromApiModel(
            new DonationCreate(
                'GBP',
                (string)($mandate->amount->amountInPence / 100),
                $campaign->getSalesforceId() ?? throw new \Exception('missing campaign sf ID'),
                'stripe',
                PaymentMethodType::Card,
                'GB',
                false,
                false,
                false,
                $donor->stripeCustomerId->stripeCustomerId,
                '0',
                $donor->donorName->first,
                $donor->donorName->last,
                $donor->emailAddress->email
            ),
            $campaign
        );
        $donation->update(
            giftAid: $mandate->hasGiftAid(),
            donorHomeAddressLine1: 'donor home address',
            donorEmailAddress: $donor->emailAddress,
            donorBillingPostcode: 'SW1 1AA'
        );

        $donation->preAuthorize($paymentDay);
        $this->em->persist($donation);
    }
}
