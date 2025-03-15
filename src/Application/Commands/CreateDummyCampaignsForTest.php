<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\PostalAddress;
use MatchBot\Domain\Salesforce18Id;
use Random\Randomizer;
use Stripe\Stripe;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:create-dummy-campaigns',
    description: 'Creates dummy campaigns in the database for dev purposes'
)]
class CreateDummyCampaignsForTest extends LockingCommand
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private Environment $environment,
        private CampaignRepository $campaignRepository,
        private CharityRepository $charityRepository,
        private EntityManagerInterface $entityManager,
        private StripeClient $stripe,
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        Assertion::notEq($this->environment, Environment::Production);

        $faker = Factory::create();
        $faker->seed(0);

        $campaignIds =
            [
                'dummyCampaign00000',
                'dummyCampaign00001',
                'dummyCampaign00002',
                'dummyCampaign00003',
                'dummyCampaign00004',
                'dummyCampaign00005',
                'dummyCampaign00006',
                'dummyCampaign00007',
                'dummyCampaign00008',
                'dummyCampaign00009',
            ];

        foreach ($campaignIds as $index => $campaignId) {
            $name = $faker->name;

            $stripeAccount = $this->stripe->accounts->create([
                    'country' => 'GB',
                    'email' => 'dev-test-stripe-account@biggive.org',
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                        'card_payments' => ['requested' => true],
                    ],
                ]
            );

            $charity = new Charity(
                salesforceId: 'CharityId' . self::randomHex(6),
                charityName: "The {$name} Charity (Look Ma, No Salesforce connection!)",
                stripeAccountId: $stripeAccount->id,
                hmrcReferenceNumber: 'H' . self::randomHex(4),
                giftAidOnboardingStatus: 'Onboarded',
                regulator: 'CCEW',
                regulatorNumber: 'Reg-no',
                time: new \DateTime('2023-10-06T18:51:27'),
                emailAddress: null,
                websiteUri: 'https://charityname.com',
                logoUri: 'https://some-logo-host/charityname/logo.png',
                phoneNumber: $faker->phoneNumber,
                address: PostalAddress::null(),
                rawData: $this->getRawCharityData(),
            );

            $output->writeln("<info>Charity created: $charity, with Stripe ID {$stripeAccount->id}</info>");

            // a more complete implementation might use the Stripe API to actually create an account (in test mode)
            // with the stripeAccountID defined above. Alternatively it could be a single test charity account shared
            // by all dev environments and defined in .env. Or we could mock-out stripe entirely but that would be a lot
            // of work and would make the scope of manual tests on dev envs too narrow.

            $salesforce18Id = Salesforce18Id::ofCampaign($campaignId);

            $existingCampaignInDb = $this->campaignRepository->findOneBySalesforceId($salesforce18Id);

            if ($existingCampaignInDb) {
                $this->entityManager->remove($existingCampaignInDb);
                $this->entityManager->flush();
            }

            $campaign = new Campaign(
                $salesforce18Id,
                $charity,
                startDate: new \DateTimeImmutable('2020-01-01'),
                endDate: new \DateTimeImmutable('3000-01-01'),
                isMatched: true,
                ready: true,
                status: 'status',
                name: "The $name Campaign (Look Ma, No Salesforce connection!)",
                currencyCode: 'GBP',
                isRegularGiving: ($index % 2) === 0,
                regularGivingCollectionEnd: null,
                thankYouMessage: 'Thank You For your generous donation!',
                rawData: $this->getRawCampaignData(),
            );

            $this->entityManager->persist($campaign);

            $output->writeln("<info>Campaign created: $campaign</info>");
        }

        $this->entityManager->flush();

        return 0;
    }

    private static function randomHex(int $length): string
    {
        return (new Randomizer())->getBytesFromString('01234567890abcdef', $length);
    }


    /**
     * @return array<string, mixed>
     */
    private function getRawCampaignData(): array
    {
        // items that are on the campaign object as first class properties are omitted -
        // we just need the extra stuff here.
        return [
            'additionalImageUris' => [0 => ['uri' => '', 'order' => 100,],],
            'aims' => [],
            'alternativeFundUse' => 'We have initiatives that require larger amounts of funding...',
            'amountRaised' => 50000.0,
            'bannerUri' => '',
            'beneficiaries' => [0 => 'General Public/Humankind', 1 => 'Women & Girls',],
            'budgetDetails' => [0 => ['description' => 'Budget item 1', 'amount' => 1000.0,],
                1 => ['description' => 'Budget item 2', 'amount' => 1000.0,],
                2 => ['description' => 'Budget item 3', 'amount' => 7500.0,],
                3 => ['description' => 'Overhead', 'amount' => 500.0,],
            ],
            'campaignCount' => null,
            'categories' => [0 => 'Health/Wellbeing', 1 => 'Medical Research', 2 => 'Mental Health',],
            'championName' => null,
            'championOptInStatement' => null,
            'championRef' => null,
            'countries' => [0 => 'United Kingdom',],
            'donationCount' => 100,
            'hidden' => false,
            'impactReporting' => 'Impact will be measured according to the pillars..',
            'impactSummary' => 'Across our pillars, ',
            'logoUri' => '',
            'matchFundsRemaining' => 50,
            'matchFundsTotal' => 5000.0,
            'parentAmountRaised' => null,
            'parentDonationCount' => null,
            'parentRef' => null,
            'parentTarget' => null,
            'parentUsesSharedFunds' => false,
            'problem' => 'The Foundation is addressing...',
            'quotes' => [0 => ['quote' => 'Dear Sam...',
                'person' => 'Alex',
            ],],
            'solution' => 'The Foundation is addressing...',
            'startDate' => '2024-10-09T08:00:00.000Z',
            'status' => 'Active',
            'summary' => 'We are raising funds...',
            'surplusDonationInfo' => null,
            'target' => 10000.0,
            'updates' => [],
            'usesSharedFunds' => false,
            'video' =>
                ['provider' => 'youtube', 'key' => '12345',],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRawCharityData(): array
    {
        // items that are on the campaign object as first class properties are omitted -
        // we just need the extra stuff here.
        return [
        'facebook' => 'https://www.facebook.com/xyz',
        'giftAidOnboardingStatus' => 'Invited to Onboard',
        'hmrcReferenceNumber' => null,
        'regulatorRegion' => 'England and Wales',
        'id' => '000000000000000000',
        'instagram' => 'https://www.linkedin.com/company/xyz',
        'linkedin' => 'https://www.linkedin.com/company/xyz/',
        'logoUri' => '',
        'optInStatement' => null,
        'twitter' => null,
    ];
    }
}
