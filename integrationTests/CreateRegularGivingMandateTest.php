<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Client\Mailer;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestData;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use MatchBot\Client\Stripe;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class CreateRegularGivingMandateTest extends IntegrationTest
{
    private MessageBusInterface $originalMessageBus;
    private int $pencePerMonth;

    public function setUp(): void
    {
        parent::setUp();
        $this->getContainer()->set(Mailer::class, $this->createStub(Mailer::class));

        $this->originalMessageBus = $this->getService(MessageBusInterface::class);

        $messageBusProphecy = $this->prophesize(MessageBusInterface::class);
        $messageBusProphecy->dispatch(Argument::type(Envelope::class), Argument::cetera())
            ->willReturnArgument(0)
            ->shouldBeCalledOnce(); // only the mandate update is initally dispatched - donations have to wait until mandate has an SF ID.

        $this->getContainer()->set(MessageBusInterface::class, $messageBusProphecy->reveal());
        $this->pencePerMonth = random_int(1, 500) * 100;


        $pi = new PaymentIntent('payment-intent-id-xyz' . IntegrationTest::randomString());
        $pi->status = PaymentIntent::STATUS_SUCCEEDED;
        $chargeId = 'charge_id_' . self::randomString();
        $pi->latest_charge = $chargeId;

        $transfer_id = 'transfer_id_' . self::randomString();

        $charge = Charge::constructFrom([
            'id' => $chargeId,
            'status' => Charge::STATUS_SUCCEEDED,
            'balance_transaction' => 'balance_transaction_id',
            'payment_method_details' => StripeObject::constructFrom([]),
            'amount' => 1,
            'transfer' => $transfer_id,
            'created' => 0,
        ]);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(
            Argument::that(fn(array $payload) => ($payload['amount'] === $this->pencePerMonth))
        )
            ->shouldBeCalledOnce()
            ->willReturn($pi);
        $stripeProphecy->retrievePaymentIntent($pi->id)->willReturn($pi);
        $stripeProphecy->confirmPaymentIntent(
            Argument::type('string'),
            Argument::cetera()
        )
            ->shouldBeCalledOnce()
            ->will(function (array $args) {
                $pi = new PaymentIntent($args[0]);
                $pi->status = PaymentIntent::STATUS_SUCCEEDED;
                return $pi;
            });
        $stripeProphecy->retrieveCharge($chargeId)->willReturn($charge);
        $stripeProphecy->retrieveBalanceTransaction('balance_transaction_id')->willReturn(
            BalanceTransaction::constructFrom([
                'id' => 'balance_transaction_id',
                'fee_details' => [['currency' => 'gbp', 'type' => 'stripe_fee']],
                'fee' => 1,
            ])
        );

        $this->getContainer()->set(Stripe::class, $stripeProphecy->reveal());

        $this->ensureDbHasDonorAccount();
    }

    public function tearDown(): void
    {
        $this->getContainer()->set(MessageBusInterface::class, $this->originalMessageBus);
    }

    public function testItCreatesRegularGivingMandate(): void
    {
        // act
        $response = $this->createRegularGivingMandate(true);

        // assert
        $this->assertSame(201, $response->getStatusCode());
        $mandateId = $this->assertMandateDetailsInDB();

        $this->assertDonationDetailsInDB($mandateId);
        $this->assertFundingWithdrawlCount($mandateId, expectedCount: 3);
    }

    /**
     * @return array<array<never>>
     */
    public function emptyArrays(): array
    {
        return [[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[],[]];
    }

    /** @dataProvider emptyArrays */
    public function testItCreatesUnMatchedRegularGivingMandate(): void
    {
            $response = $this->createRegularGivingMandate(false);

            // assert
            $this->assertSame(201, $response->getStatusCode());
            $mandateId = $this->assertMandateDetailsInDB();

            $this->assertDonationDetailsInDB($mandateId); // no they're not.
            $this->assertFundingWithdrawlCount($mandateId, expectedCount: 0);
    }


    protected function createRegularGivingMandate(
        bool $useMatchFunds
    ): ResponseInterface {
        $campaignId = $this->randomString();

        $this->addFundedCampaignAndCharityToDB($campaignId, isRegularGiving: true, fundType: FundType::ChampionFund);

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                TestData\Identity::getTestPersonMandateEndpoint(),
                headers: [
                    'X-Tbg-Auth' => TestData\Identity::getTestIdentityTokenComplete(),
                ],
                // The Symfony Serializer will throw an exception if the JSON document doesn't include all the required
                // constructor params of DonationCreate
                body: json_encode(
                    [
                    'currency' => "GBP",
                    'amountInPence' => $this->pencePerMonth,
                    'dayOfMonth' => 1,
                    'giftAid' => false,
                    'campaignId' => $campaignId,
                    'unmatched' => ! $useMatchFunds, // negated as donations will be matched by default.
                    'tbgComms' => true,
                    'charityComms' => true
                    ],
                    \JSON_THROW_ON_ERROR
                ),
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );
    }

    private function ensureDbHasDonorAccount(): void
    {
        $donorAccount = TestData\Identity::donorAccount();
        $repository = $this->getService(DonorAccountRepository::class);

        // previously I did a try-catch for UniqueConstraintViolationException but that's no good,
        // the entity manager goes away when it throws that.

        if (! $repository->findByPersonId($donorAccount->id())) {
            $repository->save($donorAccount);
        }
    }

    public function assertMandateDetailsInDB(): int
    {
        $mandateDatabaseRows = $this->db()->executeQuery(
            "SELECT * from RegularGivingMandate where donationAmount_amountInPence = ?",
            [$this->pencePerMonth]
        )
            ->fetchAllAssociative();
        $this->assertNotEmpty($mandateDatabaseRows);
        $this->assertSame($this->pencePerMonth, $mandateDatabaseRows[0]['donationAmount_amountInPence']);
        $this->assertSame(1, $mandateDatabaseRows[0]['tbgComms']);
        $this->assertSame(1, $mandateDatabaseRows[0]['charityComms']);

        $id = $mandateDatabaseRows[0]['id'];
        \assert(is_int($id));

        return $id;
    }

    public function assertDonationDetailsInDB(mixed $mandateId): void
    {
        $donationDatabaseRows = $this->db()->executeQuery(
            "SELECT * from Donation where Donation.mandate_id = ? ORDER BY mandateSequenceNumber asc",
            [$mandateId]
        )->fetchAllAssociative();

        $this->assertCount(3, $donationDatabaseRows);

        $this->assertSame('Collected', $donationDatabaseRows[0]['donationStatus']);
        $this->assertSame('PreAuthorized', $donationDatabaseRows[1]['donationStatus']);
        $this->assertSame('PreAuthorized', $donationDatabaseRows[2]['donationStatus']);

        $this->assertNull($donationDatabaseRows[0]['preAuthorizationDate']);
        $this->assertNotNull($donationDatabaseRows[1]['preAuthorizationDate']);
        $this->assertNotNull($donationDatabaseRows[2]['preAuthorizationDate']);

        $this->assertEquals((float)($this->pencePerMonth / 100), $donationDatabaseRows[0]['amount']);
        $this->assertEquals((float)($this->pencePerMonth / 100), $donationDatabaseRows[1]['amount']);
        $this->assertEquals((float)($this->pencePerMonth / 100), $donationDatabaseRows[2]['amount']);
    }

    private function assertFundingWithdrawlCount(int $mandateId, int $expectedCount): void
    {
        $fundingWithdrawls = $this->db()->executeQuery(
            "SELECT FundingWithdrawal.id from FundingWithdrawal 
             join Donation ON FundingWithdrawal.donation_id = Donation.id
                            where Donation.mandate_id = ? ORDER BY mandateSequenceNumber asc",
            [$mandateId]
        )->fetchAllAssociative();

        $this->assertCount($expectedCount, $fundingWithdrawls);
    }
}
