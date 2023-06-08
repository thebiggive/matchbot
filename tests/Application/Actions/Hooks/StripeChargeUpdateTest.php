<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use Prophecy\Argument;
use Stripe\Service\BalanceTransactionService;
use Stripe\StripeClient;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\Message\ChatMessage;

class StripeChargeUpdateTest extends StripeTest
{
    public function testUnsupportedAction(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // Payment Intent events, including cancellations, return a 204 No Content no-op for now.
        $body = $this->getStripeHookMock('pi_canceled');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testSuccessWithUnrecognisedTransactionId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded_invalid_id');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_invalidId_123'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testMissingSignature(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded');
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', '');

        $response = $app->handle($request);

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Invalid Signature',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $payload = (string) $response->getBody();

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testSuccessfulPayment(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('tr_id_t987', $donation->getTransferId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('0.37', $donation->getOriginalPspFee());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testOriginalStripeFeeInSEK(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_succeeded_sek');
        $donation = $this->getTestDonation('6000.00');
        $donation->setCurrencyCode('SEK');

        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $balanceTxnResponse = $this->getStripeHookMock('ApiResponse/bt_success_sek');
        $stripeBalanceTransactionProphecy = $this->prophesize(BalanceTransactionService::class);
        $stripeBalanceTransactionProphecy->retrieve('txn_00000000000000')
            ->shouldBeCalledOnce()
            ->willReturn(json_decode($balanceTxnResponse));
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->balanceTransactions = $stripeBalanceTransactionProphecy->reveal();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals('tr_id_t988', $donation->getTransferId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('18.72', $donation->getOriginalPspFee());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDisputeLostBehavesLikeRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDisputeWonMakesNoSubstantiveChanges(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_won');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        // The donation isn't even looked up in the actual action, because there are
        // never any data changes to make in the "won" case.
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will log
        // info to help in case of any confusion.
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testDisputeLostWithUnrecognisedTransactionId(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_unknown_pi');
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_invalidId_123'])
            ->willReturn(null)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testDisputeLostWithAmountUnexpectedlyHighIsStillProcessedButAlertsSlack(): void
    {
        // arrange (plus assert some donation repo & chatter method call counts)
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_higher_amount');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock('[test] Over-refund detected')))
            ->block((new SlackSectionBlock())->text('Over-refund detected for donation 12345678-1234-1234-1234-1234567890ab based on charge.dispute.closed (lost) hook. Donation inc. tip was 124.45 GBP and refund or dispute was 124.46 GBP'))
            ->iconEmoji(':o');
        $expectedMessage = (new ChatMessage('Over-refund detected'))
            ->options($options);

        $chatterProphecy->send($expectedMessage)->shouldBeCalledOnce();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());

        // act
        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDisputeLostWithAmountTooLowIsSkipped(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_dispute_closed_lost_unexpected_amount');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findAndLockOneBy(['transactionId' => 'pi_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will also log
        // an error so we can investigate.
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testSuccessfulFullRefund(): void
    {
        // arrange (plus assert a couple of donation repo method call counts)
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_refunded');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        // Double-check that the normal success case isn't messaging Slack.
        $chatterProphecy->send(Argument::cetera())->shouldNotBeCalled();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());

        // act
        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
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
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_over_refunded');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock('[test] Over-refund detected')))
            ->block((new SlackSectionBlock())->text('Over-refund detected for donation 12345678-1234-1234-1234-1234567890ab based on charge.refunded hook. Donation inc. tip was 124.45 GBP and refund or dispute was 134.45 GBP'))
            ->iconEmoji(':o');
        $expectedMessage = (new ChatMessage('Over-refund detected'))
            ->options($options);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(StripeChatterInterface::class, $chatterProphecy->reveal());

        // act
        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert

        // We expect a Slack notice about the unusual over-refund.
        $chatterProphecy->send($expectedMessage)->shouldBeCalledOnce();

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Refunded, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessfulTipRefund(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $webhookSecret = $this->getValidWebhookSecret($container);

        $body = $this->getStripeHookMock('ch_tip_refunded');
        $donation = $this->getTestDonation();
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();
        $entityManagerProphecy->commit()->shouldBeCalledOnce();

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('0.00', $donation->getTipAmount());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testUnsupportedOriginalChargeStatusIsSkipped(): void
    {
        // arrange
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_refunded_but_original_failed');
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $time = (string) time();
        $webhookSecret = $this->getValidWebhookSecret($container);

        // act
        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // assert
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Unsupported Status "failed"',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $payload = (string) $response->getBody();

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testUnsupportedRefundAmount(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $body = $this->getStripeHookMock('ch_unsupported_partial_refund');
        $donation = $this->getTestDonation();
        $webhookSecret = $this->getValidWebhookSecret($container);
        $time = (string) time();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->findOneBy(['chargeId' => 'ch_externalId_123'])
            ->willReturn($donation)
            ->shouldBeCalledOnce();

        $donationRepoProphecy
            ->releaseMatchFunds($donation)
            ->shouldNotBeCalled();

        $donationRepoProphecy
            ->push(Argument::type(Donation::class), false)
            ->willReturn(true)
            ->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $request = $this->createRequest('POST', '/hooks/stripe', $body)
            ->withHeader('Stripe-Signature', $this->generateSignature($time, $body, $webhookSecret));

        $response = $app->handle($request);

        // No change to any donation data. Return 204 for no change. Will also log
        // an error so we can investigate.
        $this->assertEquals('ch_externalId_123', $donation->getChargeId());
        $this->assertEquals(DonationStatus::Collected, $donation->getDonationStatus());
        $this->assertEquals('1.00', $donation->getTipAmount());
        $this->assertEquals(204, $response->getStatusCode());
    }

    private function getValidWebhookSecret(Container $container): string
    {
        /**
         * @var array{
         *    stripe: array{
         *      accountWebhookSecret: string
         *    }
         *  } $settings
         */
        $settings = $container->get('settings');
        return $settings['stripe']['accountWebhookSecret'];
    }
}
