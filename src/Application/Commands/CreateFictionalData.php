<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use Laminas\Diactoros\Uri;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\Settings;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\ApplicationStatus;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFamily;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatistics;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\CharityResponseToOffer;
use MatchBot\Domain\Currency;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentServiceProvider;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Random\Randomizer;
use Stripe\StripeClient;
use Symfony\Component\Clock\Clock;
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
 *
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:no-else-clause
 */
#[AsCommand(
    name: 'matchbot:create-fictional-data',
    description: "Creates fictional data (e.g. campaigns) for use in manual testing",
)]
class CreateFictionalData extends Command
{
    public const string SF_ID_ZERO = '000000000000000000';
    public const string SF_ID_ONE = '000000000000000001';

    public function __construct(
        private EntityManagerInterface $em,
        private CharityRepository $charityRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private CampaignRepository $campaignRepository,
        private StripeClient $stripeClient,
        private FundRepository $fundRepository,
        private Client $guzzleClient,
        private Settings $settings,
        private Clock $clock,
    ) {
        parent::__construct(null);
    }

    public function createRyftAccount(SymfonyStyle $io): string
    {
        $ryftURIPrefix = "https://sandbox-api.ryftpay.com/v1";

        $secretKey = $this->settings->ryft['secretKey'];
        Assertion::notEmpty($secretKey);

        // see https://developer.ryftpay.com/documentation/api/reference/openapi/accounts/subaccountcreate
        $headers = ['Authorization' => $secretKey];
        $request = new Request(
            method: 'POST',
            uri: $ryftURIPrefix . '/accounts',
            headers: $headers,
            body: json_encode([
                'onboardingFlow' => 'NonHosted',
                'entityType' => 'Business',
                'business' => [
                    'name' => 'Test Charity',
                    'type' => 'Charity',
                    'contactEmail' => 'test-' . TestCase::randomString() . '@biggive.org', // must be unique.
                    'registrationNumber' => '1234', // this is required for business accounts, may be a problem since some of our charities are exempt and might not be registered as companies either.
                    'registeredAddress' => [
                        "lineOne" => "123 Test Street",
                        "lineTwo" => null,
                        "city" => "Manchester",
                        "country" => "GB",
                        "postalCode" => "SP4 7DE",
                        "region" => null
                    ],
                ],
                'metadata' => [
                    'example-sub-account-metadata' => 42,
                ],
            ], \JSON_THROW_ON_ERROR),
        );

        try {
            $response = $this->guzzleClient->send($request);
        } catch (ClientException $e) {
            $io->error($e->getResponse()->getBody()->getContents());

            $id = "ac_eeeeffff-06d7-44a9-a548-eeeeeeffffff";
            $io->writeln("Could not create ryft account, using placeholder account ID $id");

            return $id;
        }
        $contents = $response->getBody()->getContents();
        $io->writeln($contents);
        $ryftData = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);


        return $ryftData['id']; // @phpstan-ignore offsetAccess.nonOffsetAccessible
    }

    /**
     * @param string $slug
     * @return MetaCampaign
     * @throws \Assert\AssertionFailedException
     */
    public function getOrCreateMetaCampaign(string $slug, CampaignFamily $family): MetaCampaign
    {
        $metaCampaignSlug = MetaCampaignSlug::of($slug);
        $metaCampaign = $this->metaCampaignRepository->getBySlug($metaCampaignSlug) ?? $this->createMetaCampaign($metaCampaignSlug, $family);
        $this->em->persist($metaCampaign);
        return $metaCampaign;
    }


    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // seed random number genrators with fixed values so we get repeatable results:
        \srand(0);
        \mt_srand(0);


        Assertion::false(Environment::current()->isProduction());

        $io = new SymfonyStyle($input, $output);
        $io->writeln("Creating fictional data for local developer testing");

        $charityOnStripe = $this->charityRepository->findOneBy(['salesforceId' => self::SF_ID_ZERO]);
        $charityOnRyft = $this->charityRepository->findOneBy(['salesforceId' => self::SF_ID_ONE]);

        $fund = $this->fundRepository->findOneBy(['salesforceId' => '000000000000000001']) ??
            new Fund('GBP', 'test fund', null, Salesforce18Id::ofFund('000000000000000001'), FundType::Pledge);

        $campaignFunding = new CampaignFunding($fund, '50.0', '50.0');
        $this->em->persist($fund);
        $this->em->persist($campaignFunding);

        $metaCampaign = $this->getOrCreateMetaCampaign('local-test', CampaignFamily::emergencyMatch);
        $this->getOrCreateMetaCampaign('women-and-girls-2024', CampaignFamily::womenGirls);
        $this->getOrCreateMetaCampaign('christmas-challenge-2025', CampaignFamily::christmasChallenge);
        $this->getOrCreateMetaCampaign('k2m25', CampaignFamily::mentalHealthFund);
        $this->getOrCreateMetaCampaign('middle-east-humanitarian-appeal-2024', CampaignFamily::emergencyMatch);

        if (!$charityOnStripe) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $charityOnStripe = $this->campaignRepository->newCharityFromCampaignData(
                // @mago-expect analysis:possibly-invalid-argument
                ['charity' => $this->getFictionalCharityData($io, PaymentServiceProvider::Stripe)] // @phpstan-ignore argument.type
            );

            $io->writeln("Created fictional charity {$charityOnStripe->getName()}, {$charityOnStripe->getSalesforceId()}");
            $this->em->persist($charityOnStripe);
        } else {
            $io->writeln("Found existing fictional charity {$charityOnStripe->getName()}, {$charityOnStripe->getSalesforceId()}");
        }

        if (!$charityOnRyft) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $charityOnRyft = $this->campaignRepository->newCharityFromCampaignData(
                // @mago-expect analysis:possibly-invalid-argument
                ['charity' => $this->getFictionalCharityData($io, PaymentServiceProvider::Ryft)] // @phpstan-ignore argument.type
            );

            $io->writeln("Created fictional charity {$charityOnRyft->getName()}, {$charityOnRyft->getSalesforceId()}");
            $this->em->persist($charityOnRyft);
        } else {
            $io->writeln("Found existing fictional charity {$charityOnRyft->getName()}, {$charityOnRyft->getSalesforceId()}");
        }

        $i = 0;
        foreach ($this->getFictionalCampaigns($metaCampaign) as $fictionalCampaign) {
            $i++;
            $campaignId = Salesforce18Id::ofCampaign($fictionalCampaign['id']);
            $campaign = $this->campaignRepository->findOneBySalesforceId($campaignId);
            if (!$campaign) {
                $campaign = Campaign::fromSfCampaignData(
                    $fictionalCampaign,
                    $campaignId,
                    $i % 2 === 0 ? $charityOnStripe : $charityOnRyft
                );

                $campaign->setSalesforceLastPull(new \DateTime());
                $stats = CampaignStatistics::zeroPlaceholder($campaign, new \DateTimeImmutable('now'));

                $io->writeln("Created fictional campaign {$campaign->getCampaignName()}, {$campaign->getSalesforceId()}");
                $this->em->persist($campaign);
                $this->em->persist($stats);
                $campaignFunding->addCampaign($campaign);
            } else {
                $io->writeln("Found existing fictional campaign {$campaign->getCampaignName()}, {$campaign->getSalesforceId()}");
            }

            $io->writeln("Donate at http://localhost:4200/donate/{$campaign->getSalesforceId()}");


            $this->em->flush();
        }

        Assertion::notEmpty($this->campaignRepository->findCampaignsForCharityPage($charityOnRyft, $this->clock->now()));
        Assertion::notEmpty($this->campaignRepository->findCampaignsForCharityPage($charityOnStripe, $this->clock->now()));

        return 0;
    }


    /**
     * @return list<SFCampaignApiResponse>
     */
    public function getFictionalCampaigns(MetaCampaign $metaCampaign): array
    {
        $campaigns = [];

        foreach (range(1, 5) as $i) {
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}1", 'Save Matchbot ' . $i, true, true, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}2", 'Save Donate Frontend ' . $i, true, false, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}3", 'Save Salesforce ' . $i, false, true, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}4", 'Save Identity ' . $i, false, false, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}5", 'Save Barney\'s Keyboard ' . $i, false, true, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}6", 'Replace Barney\'s Keyboard with a silent one ' . $i, false, false, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}7", 'Implement generics in PHP ' . $i, false, true, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}8", 'Implement open source Apex compiler ' . $i, false, false, $metaCampaign);
                $campaigns[] = $this->getFictionalCampaignData("0000000000000000{$i}9", 'Save Regtest ' . $i, false, true, $metaCampaign);
        }

        return $campaigns;
    }

    /**
     * @param MetaCampaign $metaCampaign
     * @return SFCampaignApiResponse
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     */
    private function getFictionalCampaignData(string $sfId, string $name, bool $isRegularGiving, bool $isMatched, ?MetaCampaign $metaCampaign): array
    {
        $randomSeed = \random_int(1, 100);

        $regions = [];
        $regionRandomizer = \random_int(1, 100);

        if ($regionRandomizer < 10) {
            $regions[] = ['countryName' => null, 'regionCode' => 'E09000033']; // City of Westminister
        } elseif ($regionRandomizer < 25) {
            $regions[] = ['countryName' => null, 'regionCode' => 'E09000014']; // Haringey
        } elseif ($regionRandomizer < 50) {
            $regions[] = ['countryName' => null, 'regionCode' => 'E12000007']; // London
        }
        // for full list of possible UK regions see `ONS_wards_to_larger.csv` resource in Salesforce.


        // @mago-expect analysis:invalid-return-statement
        return [ // @phpstan-ignore return.type
            'id' => $sfId,
            'charity' => [],
            'aims' => [0 => 'First Aim'],
            'isPublished' => true,
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
            // in prod bannerUri is available in multiple sizes with a `width` param added by FE. Picsum will always send the image at 1700px wide.
            'bannerUri' => "https://picsum.photos/seed/$randomSeed/1700/500",
            'locations' => [
                ['countryName' => 'United Kingdom', 'regionCode' => null],
                ...$regions,
            ],
            'isMatched' => $isMatched,
            'parentRef' => $metaCampaign?->getSlug()?->slug,
            'startDate' => '2015-08-01T00:00:00.000Z',
            'categories' => ['Education/Training/Employment', 'Religious'],
            'championRef' => null,
            'amountRaised' => 0.0,
            'championName' => null,
            'currencyCode' => 'GBP',
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
            'additionalImages' => [
                [
                    'uri' => 'https://picsum.photos/seed/' . \random_int(1, 100) . ' /1700/500',
                    'rank' => 1,
                    'altText' => 'This is the image alt-text',
                ]
            ],
            'matchFundsRemaining' => 50.0,
            'parentDonationCount' => null,
            'surplusDonationInfo' => null,
            'parentUsesSharedFunds' => false,
            'championOptInStatement' => null,
            'parentMatchFundsRemaining' => null,
            'regularGivingCollectionEnd' => null,
            'relatedApplicationStatus' => ApplicationStatus::Approved->value,
            'relatedApplicationCharityResponseToOffer' => CharityResponseToOffer::Accepted->value,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getFictionalCharityData(SymfonyStyle $io, PaymentServiceProvider $psp): array
    {
        $randomSeed = \random_int(1, 100);

        $id = null;

        if ($psp === PaymentServiceProvider::Stripe) {
            $id = self::SF_ID_ZERO;
            $stripeAccountId = $this->createStripeAccount($io);
        } else {
            $stripeAccountId = null;
        }

        if ($psp === PaymentServiceProvider::Ryft) {
            $id = self::SF_ID_ONE;
            $ryftAccountId = $this->createRyftAccount($io);
        } else {
            $ryftAccountId = null;
        }

        return [
            'id' => $id,
            'name' => 'Society for the advancement of bots and matches',
            'logoUri' =>  "https://picsum.photos/seed/$randomSeed/200/200",
            'twitter' => null,
            'website' => 'https://society-for-the-advancement-of-bots-and-matches.localhost',
            'facebook' => 'https://www.facebook.com/botsAndMatches',
            'linkedin' => 'https://www.linkedin.com/company/botsAndMatches',
            'instagram' => 'https://www.instagram.com/botsAndMatches',
            'phoneNumber' => null,
            'emailAddress' => 'bots-and-matches@example.com',
            'optInStatement' => null,
            'regulatorNumber' => '1000000',
            'regulatorRegion' => 'England and Wales',
            'stripeAccountId' => $stripeAccountId,
            'ryftAccountId' => $ryftAccountId,
            'psp' => $psp->value,
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
                        'card_payments' => ['requested' => true],
                        'pay_by_bank_payments' => ['requested' => true],
                        'transfers' => ['requested' => true],
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

    private function createMetaCampaign(MetaCampaignSlug $slug, CampaignFamily $family): MetaCampaign
    {
        $bannerURI = "https://picsum.photos/id/88/1700/500";
        // Fixed image, so we can set a focal position/region to ensure is always visible -  this is an overhead shot
        // of a road, we can say the traffic light on the RHS is the focal point at position 71%, 48%. For now this
        // is hard-coded in Front End.

        return new MetaCampaign(
            $slug,
            salesforceId: Salesforce18Id::ofMetaCampaign(TestCase::randomString()),
            title: "Local test MetaCampaign", // feel free to replace if you have a more creative idea
            currency: Currency::GBP,
            hidden: false,
            summary: 'These campaigns exist in the local Matchbot Database, they are not real and not currently expected to exist in any Salesforce org',
            bannerURI: new Uri($bannerURI),
            startDate: new \DateTimeImmutable('1990-01-01'),
            endDate: new \DateTimeImmutable('2090-01-01'),
            isRegularGiving: false,
            isEmergencyIMF: false,
            totalAdjustment: Money::zero(Currency::GBP),
            campaignFamily: $family,
        );
    }
}
