<?php

namespace integrationTests;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\ORMInvalidArgumentException;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\IntegrationTests\IntegrationTest;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
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
class CreateDonationEvenWhenEntityManagerClosesAfterFirstPersistsTest extends IntegrationTest
{
    private int $entityManagerWillCloseAfterNPersists = 3;
    private string $campaignSfID;

    public function setUp(): void
    {
        parent::setUp();

        $this->setInContainer(RetrySafeEntityManager::class, new RetrySafeEntityManager(
            $this->getContainer()->get(\Doctrine\ORM\Configuration::class),
            $this->getContainer()->get('settings')['doctrine']['connection'],
            $this->getContainer()->get(LoggerInterface::class),
            function ()  {
                return $this->thisMakeBaseEntityManager();
            }
        ));

        $this->campaignSfID = 'sfID' . random_int(1_000, 9_999);

        $campaignReturnedFromSfAPI = [
            'id' => $this->campaignSfID,
            'currencyCode' => 'GBP',
            'endDate' => '2023-11-11T17:21:50+00:00',
            'startDate' => '2023-09-11T17:21:50+00:00',
            'feePercentage' => 1.0,
            'isMatched' => true,
            'title' => 'Save the PHP Developers',
            'charity' => [
                'id' => '' . random_int(100, 999),
                'name' => 'Some Charity',
                'stripeAccountId' => 'stripe-acc-id_' . random_int(1_000, 9_999),
                'giftAidOnboardingStatus' => 'Onboarded',
                'hmrcReferenceNumber' => (string)random_int(1_000, 9_999),
                'regulatorRegion' => '',
                'regulatorNumber' => '',
            ],
        ];

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());

        $fundClientProphecy = $this->prophesize(\MatchBot\Client\Fund::class);

        $fundClientProphecy->getForCampaign(Argument::type('string'))->will(fn () => [
            [
                'id' => 'fnd' . random_int(100, 999),
                'type' => 'pledge',
                'totalAmount' => '1000',
                'isShared' => false,
                'currencyCode' => 'GBP',
                'amountForCampaign' => '1000',
            ],
        ]);

        $campaignClientProphecy = $this->prophesize(\MatchBot\Client\Campaign::class);
        $campaignClientProphecy->getById(Argument::type('string'))->willReturn(
            $campaignReturnedFromSfAPI
        );

        $this->setInContainer(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());
        $this->setInContainer(\MatchBot\Client\Campaign::class, $campaignClientProphecy->reveal());
        $this->setInContainer(\MatchBot\Client\Fund::class, $fundClientProphecy->reveal());

        $campaignRepo = $this->getContainer()->get(CampaignRepository::class);
        assert($campaignRepo instanceof CampaignRepository);
        $campaignRepo->setClient($campaignClientProphecy->reveal());

        $donationRepo = $this->getContainer()->get(DonationRepository::class);
        assert($donationRepo instanceof DonationRepository);
        $donationRepo->setClient($donationClientProphecy->reveal());
    }

    /**
     * This test is the reverse of a normal test - we assert that a bug exists, instead of that the app works properly.
     *
     * When the bug is fixed this will fail and can be reversed in sense to make it pass again, or deleted if not
     * needed.
     */
    public function testItCanCreateAnDonationUsingMultipleUnderlyingEntityManagers(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

//        $this->expectException(ORMInvalidArgumentException::class);
//        $this->expectExceptionMessage(
//            "A new entity was found through the relationship 'MatchBot\Domain\CampaignFunding#campaigns' that was not" .
//            " configured to cascade persist operations for entity"
//        );

        // full message:
        // Doctrine\ORM\ORMInvalidArgumentException: A new entity was found through the relationship
        // 'MatchBot\Domain\CampaignFunding#campaigns' that was not configured to cascade persist operations for entity:
        //Campaign ID #342, SFId: 43241206-573e-4976. To solve this issue: Either explicitly call
        // EntityManager#persist() on this unknown entity or configure cascade persist this association in the mapping
        //for example @ManyToOne(..,cascade={"persist"}).

        $this->createDonation(withPremadeCampaign: false);
        $donationFetchedFromDB = $this->db()->fetchAssociative(
            "SELECT * from Donation
                   JOIN Campaign on Donation.campaign_id = Campaign.id
         where Campaign.salesforceId = '{$this->campaignSfID}';"
        );

        $this->assertSame([], $donationFetchedFromDB);
    }

    /**
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    function thisMakeBaseEntityManager(): EntityManagerInterface
    {
        $em = EntityManager::create(
        // for this test we don't need the RetrySafeEntityManager - using the standard EM makes things simpler.
            $this->getContainer()->get('settings')['doctrine']['connection'],
            $this->getContainer()->get(\Doctrine\ORM\Configuration::class),
        );

         return new class($em, $this->entityManagerWillCloseAfterNPersists) extends EntityManagerDecorator {

                public function __construct(EntityManagerInterface $wrapped, private int $persistsLeftBeforeClosingTime)
                {
                    parent::__construct($wrapped);
                }

                public function flush(mixed $entity = null): void
                {
                    $this->persistsLeftBeforeClosingTime--;
                    if ($this->persistsLeftBeforeClosingTime <= 0) {
                        throw new EntityManagerClosed();
                    }

                    parent::flush();
                }
            };
    }
}
