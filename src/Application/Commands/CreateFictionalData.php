<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Salesforce18Id;
use Random\Randomizer;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This creates a fictional charity and campign in the database, enough to allow viewing the donation page at
 * http://localhost:4200/donate/000000000000000000 . However, it isn't currently possible to advance past step
 * one in that donation form as we need to use a real stripe account ID to connect to the real stripe and make
 * a payment intent. For now an account used for testing beyond that point will need to be fetched from Salesforce.
 *
 * @psalm-import-type SFCampaignApiResponse from CampaignClient
 */
#[AsCommand(
    name: 'matchbot:create-fictional-data',
    description: "Creates fictional data (e.g. campaigns) for use in manual testing",
)]
class CreateFictionalData extends Command
{
    public const string SF_ID_ZERO = '000000000000000000';

    public function __construct(
        private EntityManagerInterface $em,
        private CharityRepository $charityRepository,
        private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
        private StripeClient $stripeClient,
    ) {
        parent::__construct(null);
    }


    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Assertion::false(Environment::current()->isProduction());

        $io = new SymfonyStyle($input, $output);
        $io->writeln("Creating fictional data for local developer testing");

        $charity = $this->charityRepository->findOneBy(['salesforceId' => self::SF_ID_ZERO]);
        $fund = new Fund('GBP', 'test fund', Salesforce18Id::ofFund('000000000000000001'), FundType::Pledge);
        $campaignFunding = new CampaignFunding($fund, '50.0', '50.0');
        $this->em->persist($fund);
        $this->em->persist($campaignFunding);


        if (!$charity) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $charity = $this->campaignRepository->newCharityFromCampaignData(
                ['charity' => $this->getFictionalCharityData($io)] // @phpstan-ignore argument.type
            );

            $io->writeln("Created fictional charity {$charity->getName()}, {$charity->getSalesforceId()}");
            $this->em->persist($charity);
        } else {
            $io->writeln("Found existing fictional charity {$charity->getName()}, {$charity->getSalesforceId()}");
        }

        foreach ($this->getFictionalCampaigns() as $fictionalCampaign) {
            $campaignId = Salesforce18Id::ofCampaign($fictionalCampaign['id']);
            $campaign = $this->campaignRepository->findOneBySalesforceId($campaignId);
            if (!$campaign) {
                $campaign = \MatchBot\Domain\Campaign::fromSfCampaignData(
                    $fictionalCampaign,
                    $campaignId,
                    $charity
                );

                $campaign->setSalesforceLastPull(new \DateTime());

                $io->writeln("Created fictional campaign {$campaign->getCampaignName()}, {$campaign->getSalesforceId()}");
                $this->em->persist($campaign);
                $campaignFunding->addCampaign($campaign);
            } else {
                $io->writeln("Found existing fictional campaign {$campaign->getCampaignName()}, {$campaign->getSalesforceId()}");
            }

            $io->writeln("Donate at http://localhost:4200/donate/{$campaign->getSalesforceId()}");


            $this->em->flush();

            $renderedCampaign = $this->campaignService->renderCampaign($campaign, metaCampaign: null);

            $errors = $renderedCampaign['errors'] ?? [];
            \assert(\is_array($errors));

            if ($errors !== []) {
                $io->error("Campaign has errors - may not match expectations of frontend:");
                $io->listing($errors);
                return 1;
            }
        }

        return 0;
    }


    /**
     * @return list<SFCampaignApiResponse>
     */
    public function getFictionalCampaigns(): array
    {
        return [
            $this->getFictionalCampaignData('000000000000000001', 'Save Matchbot', true, true),
            $this->getFictionalCampaignData('000000000000000002', 'Save Donate Frontend', true, false),
            $this->getFictionalCampaignData('000000000000000003', 'Save Salesforce', false, true),
            $this->getFictionalCampaignData('000000000000000004', 'Save Identity', false, false),
            $this->getFictionalCampaignData('000000000000000005', 'Save Barney\'s Keyboard', false, true),
            $this->getFictionalCampaignData('000000000000000006', 'Replace Barney\'s Keyboard with a silent one', false, false),
            $this->getFictionalCampaignData('000000000000000007', 'Implement generics in PHP', false, true),
            $this->getFictionalCampaignData('000000000000000008', 'Implement open source Apex compiler', false, false),
            $this->getFictionalCampaignData('000000000000000009', 'Save Regtest', false, true),
        ];
    }

    /**
     * @return SFCampaignApiResponse
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     */
    private function getFictionalCampaignData(string $sfId, string $name, bool $isRegularGiving, bool $isMatched): array
    {
        return [ // @phpstan-ignore return.type
            'id' => $sfId,
            'charity' => [],
            'aims' => [0 => 'First Aim'],
            'ready' => true,
            'title' => $name,
            'video' => null,
            'hidden' => false,
            'quotes' => [],
            'status' => 'Active',
            'target' => 100.0,
            'endDate' => '2095-08-01T00:00:00.000Z',
            'logoUri' => null,
            'problem' => 'Matchbot is threatened!',
            'summary' => "We can $name",
            'updates' => [],
            'solution' => 'do the saving',
            'bannerUri' => null,
            'countries' => [0 => 'United Kingdom',],
            'isMatched' => $isMatched,
            'parentRef' => null,
            'startDate' => '2015-08-01T00:00:00.000Z',
            'categories' => ['Education/Training/Employment', 'Religious'],
            'championRef' => null,
            'amountRaised' => 0.0,
            'championName' => null,
            'currencyCode' => 'GBP',
            'parentTarget' => null,
            'beneficiaries' => ['Animals'],
            'budgetDetails' => [
                ['amount' => 23.0, 'description' => 'Improve the code'],
                ['amount' => 27.0, 'description' => 'Invent a new programing paradigm'],
            ],
            'campaignCount' => null,
            'donationCount' => 0,
            'impactSummary' => null,
            'impactReporting' => null,
            'isRegularGiving' => $isRegularGiving,
            'matchFundsTotal' => 50.0,
            'thankYouMessage' => 'Thank you for helping us save matchbot! We will be able to match twice as many bots now!',
            'usesSharedFunds' => false,
            'alternativeFundUse' => null,
            'parentAmountRaised' => null,
            'additionalImageUris' => [],
            'matchFundsRemaining' => 50.0,
            'parentDonationCount' => null,
            'surplusDonationInfo' => null,
            'parentUsesSharedFunds' => false,
            'championOptInStatement' => null,
            'parentMatchFundsRemaining' => null,
            'regularGivingCollectionEnd' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getFictionalCharityData(SymfonyStyle $io): array
    {
        $stripeAccountId = $this->createStripeAccount($io);

        return [
            'id' => self::SF_ID_ZERO,
            'name' => 'Society for the advancement of bots and matches',
            'logoUri' =>  null,
            'twitter' => null,
            'website' => 'https://society-for-the-advancement-of-bots-and-matches.localhost',
            'facebook' => 'https://www.facebook.com/botsAndMatches',
            'linkedin' => 'https://www.linkedin.com/company/botsAndMatches',
            'instagram' => 'https://www.instagram.com/botsAndMatches',
            'phoneNumber' => null,
            'emailAddress' => 'bots-and-matches@example.com',
            'postalAddress' => [
                'city' => 'London',
                'line1' => 'Matchbot Street',
                'line2' => 'Near Donate-Frontend',
                'country' => 'United Kingdom',
                'postalCode' => 'WC2B 5LX'
            ],
            'optInStatement' => null,
            'regulatorNumber' => '1000000',
            'regulatorRegion' => 'England and Wales',
            'stripeAccountId' => $stripeAccountId,
            'hmrcReferenceNumber' => null,
            'giftAidOnboardingStatus' => 'Invited to Onboard',
        ];
    }

    private function createStripeAccount(SymfonyStyle $io): string
    {
        try {
            $account = $this->stripeClient->accounts->create([
                    'country' => 'GB',
                    'email' => 'dev-test-stripe-account@biggive.org',
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                        'card_payments' => ['requested' => true],
                    ],
                ]);

            $id = $account->id;

            $io->writeln("Created test stripe account: $id");

            return $id;
        } catch (\Exception) {
            $id = "acct_0000000000" . (new Randomizer())->getBytesFromString('0123456789', 6);

            $io->writeln("Could not create stripe account, using placeholder account ID $id");

            return $id;
        }
    }
}
