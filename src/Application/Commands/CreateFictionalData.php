<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Uri;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\ApplicationStatus;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFamily;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
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
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
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
        private MetaCampaignRepository $metaCampaignRepository,
        private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
        private StripeClient $stripeClient,
        private FundRepository $fundRepository,
    ) {
        parent::__construct(null);
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
        Assertion::false(Environment::current()->isProduction());

        $io = new SymfonyStyle($input, $output);
        $io->writeln("Creating fictional data for local developer testing");

        $charity = $this->charityRepository->findOneBy(['salesforceId' => self::SF_ID_ZERO]);

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

        foreach ($this->getFictionalCampaigns($metaCampaign) as $fictionalCampaign) {
            $campaignId = Salesforce18Id::ofCampaign($fictionalCampaign['id']);
            $campaign = $this->campaignRepository->findOneBySalesforceId($campaignId);
            if (!$campaign) {
                $campaign = Campaign::fromSfCampaignData(
                    $fictionalCampaign,
                    $campaignId,
                    $charity
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
    public function getFictionalCampaigns(MetaCampaign $metaCampaign): array
    {
        return [
            $this->getFictionalCampaignData('000000000000000001', 'Save Matchbot', true, true, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000002', 'Save Donate Frontend', true, false, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000003', 'Save Salesforce', false, true, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000004', 'Save Identity', false, false, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000005', 'Save Barney\'s Keyboard', false, true, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000006', 'Replace Barney\'s Keyboard with a silent one', false, false, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000007', 'Implement generics in PHP', false, true, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000008', 'Implement open source Apex compiler', false, false, $metaCampaign),
            $this->getFictionalCampaignData('000000000000000009', 'Save Regtest', false, true, $metaCampaign),
        ];
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
            // in prod bannerUri is available in multiple sizes with a `width` param added by FE. Picsum will always send the image at 1700px wide.
            'bannerUri' => "https://picsum.photos/seed/$randomSeed/1700/500",
            'countries' => [0 => 'United Kingdom',],
            'isMatched' => $isMatched,
            'parentRef' => $metaCampaign?->getSlug()?->slug,
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
            'additionalImageUris' => [
                [
                    'uri' => 'https://picsum.photos/seed/' . \random_int(1, 100) . ' /1700/500',
                    'order' => 1,
                    'alt_text' => 'This is the image alt-text',
                ]
            ],
            'matchFundsRemaining' => 50.0,
            'parentDonationCount' => null,
            'surplusDonationInfo' => null,
            'parentUsesSharedFunds' => false,
            'championOptInStatement' => null,
            'parentMatchFundsRemaining' => null,
            'regularGivingCollectionEnd' => null,
            'relatedApplicationStatus' => ApplicationStatus::Approved,
            'relatedApplicationCharityResponseToOffer' => CharityResponseToOffer::Accepted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getFictionalCharityData(SymfonyStyle $io): array
    {
        $randomSeed = \random_int(1, 100);

        $stripeAccountId = $this->createStripeAccount($io);

        return [
            'id' => self::SF_ID_ZERO,
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
            status: 'Active',
            masterCampaignStatus: MetaCampaign::STATUS_VIEW_CAMPAIGN,
            hidden: false,
            summary: 'These campaigns exist in the local Matchbot Database, they are not real and not currently expected to exist in any Salesforce org',
            bannerURI: new Uri($bannerURI),
            startDate: new \DateTimeImmutable('1990-01-01'),
            endDate: new \DateTimeImmutable('2090-01-01'),
            isRegularGiving: false,
            isEmergencyIMF: false,
            totalAdjustment: Money::zero(Currency::GBP),
            imfCampaignTargetOverride: Money::zero(),
            matchFundsTotal: Money::zero(),
            campaignFamily: $family,
        );
    }
}
