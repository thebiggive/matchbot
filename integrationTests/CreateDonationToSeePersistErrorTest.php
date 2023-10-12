<?php

namespace integrationTests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\IntegrationTests\IntegrationTest;
use Prophecy\Argument;
use Ramsey\Uuid\Uuid;


/**
 * Trying to reproduce and then fix errors such as this just seen in production that presumably lead to people getting
 * the "Sorry, we can't register you're donation right now" error:
 *
 * A new entity was found through the relationship
 * 'MatchBot\Domain\FundingWithdrawal#donation'
 * that was not configured to cascade persist operations for entity:
 * Donation 04d86e82-6852-11ee-a7a9-d377c1aac266 to
 * CHARITY. To solve this issue: Either explicitly call EntityManager#persist() on this unknown entity or configure
 * cascade persist this association in the mapping for example @ManyToOne(..,cascade={"persist"}).
 *
 * * A new entity was found through the relationship
 * 'MatchBot\Domain\FundingWithdrawal#campaignFunding'
 * that was not configured to cascade persist operations for entity:
 * CampaignFunding, ID #12345, created 2023-10-11T11:05:30+00:00 of fund SF ID a2346342462341235Q.
 * To solve this issue:
 * Either explicitly call EntityManager#persist() on this unknown entity or configure cascade persist this association
 * in the mapping for example @ManyToOne(..,cascade={"persist"}).
 */
class CreateDonationToSeePersistErrorTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();

        /** @var \DI\Container $container */
        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());

        $fundClientProphecy = $this->prophesize(\MatchBot\Client\Fund::class);
        $fundClientProphecy->getForCampaign(Argument::type('string'))->willReturn([
            [
                'id' => 'fnsfid',
                'type' => 'pledge',
                'totalAmount' => '1000',
                'isShared' => false,
                'currencyCode' => 'GBP',
                'amountForCampaign' => '1000',
            ],
        ]);

        $campaignClientProphecy = $this->prophesize(\MatchBot\Client\Campaign::class);
        $campaignClientProphecy->getById(Argument::type('string'))->willReturn(
            [
                'id' => '1',
                'currencyCode' => 'GBP',
                'endDate' => '2023-11-11T17:21:50+00:00',
                'startDate' => '2023-09-11T17:21:50+00:00',
                'feePercentage' => 1.0,
                'isMatched' => true,
                'title' => 'Save the PHP Developers',
                'charity' => [
                    'id' => '',
                    'name' => 'Some Charity',
                    'stripeAccountId' => 'stripe-acc-id',
                    'giftAidOnboardingStatus' => 'Onboarded',
                    'hmrcReferenceNumber' => '',
                    'regulatorRegion' => '',
                    'regulatorNumber' => '',
                ],
            ]
        );

        /**
         * the error is only thrown if this line is here. Query could we somehow have two different entity managers
         * at once in prod and could that be causing the problem? Our RetrySafeEntityManager and the standard doctrine
         * one as below?
         *
         * @psalm-suppress DeprecatedMethod
         * @psalm-suppress MixedArrayAccess
         * @psalm-suppress MixedArgument
         * We're using EntityManager::create already inside RetrySafeEntityManager - while we have it there we may as
         * well have it in this test. Also probably not worthwhile fixing the Mixed issues here before they're
         * fixed in the similar prod code.
         */
        $container->set(EntityManagerInterface::class, EntityManager::create(
            // for this test we don't need the RetrySafeEntityManager - using the standard EM makes things simpler.
            $container->get('settings')['doctrine']['connection'],
            $container->get(\Doctrine\ORM\Configuration::class),
        ));

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());
        $container->set(\MatchBot\Client\Campaign::class, $campaignClientProphecy->reveal());
        $container->set(\MatchBot\Client\Fund::class, $fundClientProphecy->reveal());

        $campaignRepo = $container->get(CampaignRepository::class);
        assert($campaignRepo instanceof CampaignRepository);
        $campaignRepo->setClient($campaignClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        assert($donationRepo instanceof DonationRepository);
        $donationRepo->setClient($donationClientProphecy->reveal());


    }

    public function testItCreatesADonationWithoutAPersistError(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // act
        $response = $this->createDonation(withPremadeCampaign: false);

        // assert

        /** @var array{donation: array<string, string>} $decoded */
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Some Charity', $decoded['donation']['charityName']);
        $this->assertNotEmpty($decoded['donation']['transactionId']);
        $uuid = $decoded['donation']['donationId'];
        $this->assertTrue(Uuid::isValid($uuid));

        $donationFetchedFromDB = $this->db()->fetchAssociative("SELECT * from Donation where Donation.uuid = '$uuid';");
        assert(is_array($donationFetchedFromDB));
        $this->assertSame('100.00', $donationFetchedFromDB['amount']);
    }
}
