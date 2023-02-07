<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Application\SlackChannelChatterFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Handle payout.paid and payout.failed events from a Stripe Connect webhook.
 *
 * @return Response
 */
class StripePayoutUpdate extends Stripe
{

    private SlackChannelChatterFactory $chatterFactory;

    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
        private RoutableMessageBus $bus,
        SlackChannelChatterFactory $chatterFactory
    ) {
        parent::__construct($container, $logger);
        $this->chatterFactory = $chatterFactory;
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $this->request,
            $this->stripeSettings['connectAppWebhookSecret'],
            true,
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $this->logger->info(sprintf(
            'Received Stripe Connect app event type "%s" on account %s',
            $this->event->type,
            $this->event->account,
        ));


        switch ($this->event->type) {
            case Event::PAYOUT_PAID:
                return $this->handlePayoutPaid($this->event);
            case Event::PAYOUT_FAILED:
                $failureMessage = sprintf(
                    'payout.failed for ID %s, account %s',
                    $this->event->data->object->id,
                    $this->event->account,
                );
                $stripeChannel = $this->chatterFactory->makeChatter('stripe');
                $stripeChannel->send(new ChatMessage($failureMessage));
                $this->logger->warning($failureMessage);

                return $this->respond(new ActionPayload(200));
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $this->event->type));
                return $this->respond(new ActionPayload(204));
        }
    }

    private function handlePayoutPaid(Event $event): Response
    {
        $payoutId = $event->data->object->id;

        if (!$event->data->object->automatic) {
            // If we try to use the `payout` filter attribute in the `balanceTransactions` call
            // in the manual payout case, Stripe errors out with "Balance transaction history
            // can only be filtered on automatic transfers, not manual".
            $this->logger->warning(sprintf('Skipping processing of manual Payout ID %s', $payoutId));
            return $this->respond(new ActionPayload(204));
        }

        $message = (new StripePayout())
            ->setConnectAccountId($event->account)
            ->setPayoutId($payoutId);

        $stamps = [
            new BusNameStamp(Event::PAYOUT_PAID),
            new TransportMessageIdStamp("payout.paid.$payoutId"),
        ];

        try {
            $this->bus->dispatch(new Envelope($message, $stamps));
        } catch (TransportException $exception) {
            $this->logger->error(sprintf(
                'Payout processing queue dispatch error %s. Request body: %s.',
                $exception->getMessage(),
                $this->request->getBody(),
            ));

            return $this->respond(new ActionPayload(500));
        }

        return $this->respondWithData($event->data->object);
    }
}
