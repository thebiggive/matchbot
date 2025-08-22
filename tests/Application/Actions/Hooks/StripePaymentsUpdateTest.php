<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Settings;
use MatchBot\Client\Mailer;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\EmailVerificationTokenRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Tests\Domain\InMemoryDonationRepository;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Stripe\BalanceTransaction;
use Stripe\Service\BalanceTransactionService;
use Stripe\StripeClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\Message\ChatMessage;

class StripePaymentsUpdateTest extends StripeTest
{
    private const string DONATION_UUID = '5cacc86a-b405-11ef-a4a5-9fcdb7039df1';
    private InMemoryDonationRepository $donationRepository;

    /** @var ObjectProphecy<Mailer> */
    private $mailerClientProphecy;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $container = $this->getContainer();
        \assert($container instanceof Container);
        $container->set(EntityManagerInterface::class, $this->createStub(EntityManagerInterface::class));
        $container->set(CampaignRepository::class, $this->createStub(CampaignRepository::class));
        $container->set(RegularGivingMandateRepository::class, $this->createStub(RegularGivingMandateRepository::class));
        $container->set(FundRepository::class, $this->createStub(FundRepository::class));
        $container->set(CampaignFundingRepository::class, $this->prophesize(CampaignFundingRepository::class)->reveal());


        $this->donationRepository = new InMemoryDonationRepository();
        $container->set(DonationRepository::class, $this->donationRepository);

        $this->mailerClientProphecy = $this->prophesize(Mailer::class);
        $container->set(Mailer::class, $this->mailerClientProphecy->reveal());
    }

    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        // Payout Object events, return a 204 No Content no-op for now.
        $body = $this->getStripeHookMock('po_created');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testSuccessWithUnrecognisedTransactionId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded_invalid_id');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testMissingSignature(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded');
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', '');

        $response = $app->handle($request);

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Invalid Signature',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $payload = (string) $response->getBody();

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSuccessfulPayment(): void
    {
        /** @var Container $container */
        $container = $this->getContainer();

        // Amounts set to match Stripe mocks' current values
        $donation = $this->getTestDonation(amount: '6.00', tipAmount: '0.00', collected: false);
        $donation->setTransactionId('pi_externalId_123');
        $this->donationRepository->store($donation);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledTimes(2)
            ->willReturn(BalanceTransaction::constructFrom((array) json_decode($balanceTxnResponse, associative: true)));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();  // @phpstan-ignore property.notFound

        $this->mailerClientProphecy->send(Argument::type(EmailMessage::class))->shouldBeCalledOnce();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(EmailVerificationTokenRepository::class, $this->createStub(EmailVerificationTokenRepository::class));
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());
        $container->set(Mailer::class, $this->mailerClientProphecy->reveal());

        $chargeSucceededData = $this->getWebhookData('ch_succeeded');
        /** @psalm-suppress MixedArrayAssignment */
        $chargeSucceededData['data']['object']['amount'] = $donation->getAmountFractionalIncTip();

        $chargeUpdatedData = $this->getWebhookData('ch_updated');

        $succeededResponse = $this->sendWebhook($chargeSucceededData);
        $updatedResponse = $this->sendWebhook($chargeUpdatedData);

        $this->assertSame(200, $succeededResponse->getStatusCode());
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame('tr_id_from_test_donation', $donation->getTransferId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('0.37', $donation->getOriginalPspFee());
        $this->assertSame(200, $updatedResponse->getStatusCode());
    }

    public function testOriginalStripeFeeInSEK(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $donation = $this->getTestDonation('6000.00', currencyCode: 'SEK');
        $this->donationRepository->store($donation);
        /** @var array<string, mixed> $webhookContent */
        $webhookContent = json_decode(
            $this->getStripeHookMock('ch_succeeded_sek'),
            associative: true,
            flags: \JSON_THROW_ON_ERROR
        );

        /**
         * @psalm-suppress MixedArrayAssignment
         */
        $webhookContent['data']['object']['amount'] = $donation->getAmountFractionalIncTip();

        $body = json_encode($webhookContent, \JSON_THROW_ON_ERROR);

        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success_sek');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledOnce()
            ->willReturn(BalanceTransaction::constructFrom((array) json_decode($balanceTxnResponse, associative: true)));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        @$stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();  // @phpstan-ignore property.notFound

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame('tr_id_t988', $donation->getTransferId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('18.72', $donation->getOriginalPspFee());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDisputeLostBehavesLikeRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost');
        $donation = $this->getTestDonation();
        $donation->setTransactionId('pi_externalId_123');
        $this->donationRepository->store($donation);
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->releaseMatchFundsInTransaction($donation->getUuid())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationService::class, $donationServiceProphecy->reveal());

        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDisputeWonMakesNoSubstantiveChanges(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_won');
        $donation = $this->getTestDonation();
        $donation->setTransactionId('pi_externalId_123');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        // The donation isn't even looked up in the actual action, because there are
        // never any data changes to make in the "won" case.
        // not using in memory repository because its hard to assert a negative with it.
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will log
        // info to help in case of any confusion.
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDisputeLostWithUnrecognisedTransactionId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_unknown_pi');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testDisputeLostWithAmountUnexpectedlyHighIsStillProcessedButAlertsSlack(): void
    {
        // arrange (plus assert some donation repo & chatter method call counts)
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_higher_amount');
        $donation = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));
        $donation->setTransactionId('pi_externalId_123');
        $this->donationRepository->store($donation);
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock('[test] Over-refund detected')))
            ->block((new SlackSectionBlock())->text(
                'Over-refund detected for donation ' . self::DONATION_UUID . ' based on ' .
                'charge.dispute.closed (lost) hook. Donation inc. tip was 124.45 GBP and refund or dispute was ' .
                '124.46 GBP'
            ))
            ->iconEmoji(':o');
        $expectedMessage = (new ChatMessage('Over-refund detected'))
            ->options($options);

        $chatterProphecy->send($expectedMessage)->shouldBeCalledOnce();

        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->releaseMatchFundsInTransaction($donation->getUuid())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());
        $container->set(DonationService::class, $donationServiceProphecy->reveal());

        // act
        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDisputeLostWithAmountTooLowIsSkipped(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_unexpected_amount');
        $donation = $this->getTestDonation();
        $donation->setTransactionId('pi_externalId_123');
        $this->donationRepository->store($donation);
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will also log
        // an error so we can investigate.
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testSuccessfulFullRefund(): void
    {
        // arrange (plus assert a couple of donation repo method call counts)
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_refunded');
        $donation = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));
        $this->setChargeIdOnDonation($donation, 'ch_externalId_123');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        // Double-check that the normal success case isn't messaging Slack.
        $chatterProphecy->send(Argument::cetera())->shouldNotBeCalled();

        $this->donationRepository->store($donation);

        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->releaseMatchFundsInTransaction($donation->getUuid())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());
        $container->set(DonationService::class, $donationServiceProphecy->reveal());

        // act
        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Verifies that:
     * * Slack gets a warning with the correct details
     * * The donation record is updated with the new status, and
     * * Match funds are released.
     */
    public function testOverRefundSendsSlackNoticeAndUpdatesRecord(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_over_refunded');
        $donation = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));
        $this->setChargeIdOnDonation($donation, 'ch_externalId_123');
        $this->donationRepository->store($donation);

        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock('[test] Over-refund detected')))
            ->block((new SlackSectionBlock())->text(
                'Over-refund detected for donation ' . self::DONATION_UUID . ' based on ' .
                'charge.refunded hook. Donation inc. tip was 124.45 GBP and refund or dispute was 134.45 GBP'
            ))
            ->iconEmoji(':o');
        $expectedMessage = (new ChatMessage('Over-refund detected'))
            ->options($options);

        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->releaseMatchFundsInTransaction($donation->getUuid())
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());
        $container->set(DonationService::class, $donationServiceProphecy->reveal());

        // act
        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert

        // We expect a Slack notice about the unusual over-refund.
        $chatterProphecy->send($expectedMessage)->shouldBeCalledOnce();

        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSuccessfulTipRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();
        $webhookSecret = $this->getValidWebhookSecret($container);

        $body = $this->getStripeHookMock('ch_tip_refunded');
        $donation = $this->getTestDonation();
        $this->setChargeIdOnDonation($donation, 'ch_externalId_123');
        $this->donationRepository->store($donation);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->getRepository(CampaignFunding::class)->willReturn($this->createStub(CampaignFundingRepository::class));
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('0.00', $donation->getTipAmount());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsupportedOriginalChargeStatusIsSkipped(): void
    {
        // arrange
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_refunded_but_original_failed');
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        // act
        /** @var array<string, mixed> $bodyArray */
        $bodyArray = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $response = $this->sendWebhook($bodyArray);

        // assert
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Unsupported Status "failed"',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $payload = (string) $response->getBody();

        $this->assertSame($expectedSerialised, $payload);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUnsupportedRefundAmount(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $this->getContainer();

        $body = $this->getStripeHookMock('ch_unsupported_partial_refund');
        $donation = $this->getTestDonation();
        $this->setChargeIdOnDonation($donation, 'ch_externalId_123');
        $this->donationRepository->store($donation);
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $request = self::createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will also log
        // an error so we can investigate.
        $this->assertSame('ch_externalId_123', $donation->getChargeId());
        $this->assertSame(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertSame('1.00', $donation->getTipAmount());
        $this->assertSame(204, $response->getStatusCode());
    }

    private function getValidWebhookSecret(Container $container): string
    {
        $settings = $container->get(Settings::class);
        return $settings->stripe['accountWebhookSecret'];
    }

    #[\Override]
    public function getContainer(): ContainerInterface
    {
        $container = parent::getContainer();
        \assert($container instanceof Container);
        $container->set(DonorAccountRepository::class, $this->createStub(DonorAccountRepository::class));

        $container->set(LockFactory::class, new LockFactory(new InMemoryStore()));

        return $container;
    }

    public function setChargeIdOnDonation(Donation $donation, string $chargeId): void
    {
        $donation->collectFromStripeCharge(
            $chargeId,
            (int) ((float) $donation->getTotalPaidByDonor() * 100.0),
            '-',
            null,
            null,
            '0',
            0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getWebhookData(string $mockName): array
    {
        /** @var array<string, mixed> $mock */
        $mock = json_decode(
            $this->getStripeHookMock($mockName),
            associative: true,
            flags: \JSON_THROW_ON_ERROR
        );

        return $mock;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendWebhook(array $data): ResponseInterface
    {
        $app = $this->getAppInstance();
        $container = $this->getContainer();
        \assert($container instanceof Container);
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $request = self::createRequest('POST', '/hooks/stripe', $body = json_encode($data, \JSON_THROW_ON_ERROR))
            ->withHeader('Stripe-Signature', self::generateSignature($time, $body, $webhookSecret));

        return $app->handle($request);
    }
}
