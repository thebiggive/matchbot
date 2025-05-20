<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Application\Notifier\StripeChatterInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Handle payout.paid and payout.failed events from a Stripe Connect webhook.
 *
 * @return Response
 */
class StripePayoutUpdate extends Stripe
{
    private ChatterInterface $chatter;

    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
        private RoutableMessageBus $bus,
    ) {
        /**
         * @var ChatterInterface $chatter
         * Injecting `StripeChatterInterface` directly doesn't work because `Chatter` itself
         * is final and does not implement our custom interface.
         */
        $chatter = $container->get(StripeChatterInterface::class); // @phpstan-ignore varTag.type
        $this->chatter = $chatter;

        parent::__construct($container, $logger);
    }

    /**
     * @return Response
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $request,
            $this->stripeSettings['connectAppWebhookSecret'],
            true,
            $response,
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $event = $this->event ?? throw new \RuntimeException("Stripe event not set");

        $connectedAccountId = $event->account;
        Assertion::notNull($connectedAccountId, 'Connected Account ID should not be null');

        $this->logger->info(sprintf(
            'Received Stripe Connect app event type "%s" on account %s',
            $event->type,
            $connectedAccountId,
        ));


        switch ($event->type) {
            case Event::PAYOUT_PAID:
                return $this->handlePayoutPaid($request, $event, $response);
            case Event::PAYOUT_FAILED:
                /**
                 * @var string $id
                 */
                $id = $event->data->object->id;
                $failureMessage = sprintf(
                    'payout.failed for ID %s, account %s',
                    $id,
                    $connectedAccountId,
                );

                $this->logger->warning($failureMessage);
                /** @var string $env */
                $env = getenv('APP_ENV');
                $failureMessageWithContext = sprintf(
                    '[%s] %s',
                    $env,
                    $failureMessage,
                );
                $this->chatter->send(new ChatMessage($failureMessageWithContext));

                return $this->respond($response, new ActionPayload(200));
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $event->type));
                return $this->respond($response, new ActionPayload(204));
        }
    }

    private function handlePayoutPaid(Request $request, Event $event, Response $response): Response
    {
        /**
         * @var \Stripe\StripeObject&object{id: string, automatic: bool} $object
         */
        $object = $event->data->object;
        $payoutId = $object->id;

        if (!$object->automatic) {
            // If we try to use the `payout` filter attribute in the `balanceTransactions` call
            // in the manual payout case, Stripe errors out with "Balance transaction history
            // can only be filtered on automatic transfers, not manual".
            $this->logger->warning(sprintf('Skipping processing of manual Payout ID %s', $payoutId));
            return $this->respond($response, new ActionPayload(204));
        }

        $connectAccountId = $event->account;
        Assertion::notNull($connectAccountId, 'Connected Account ID should not be null');

        $message = new StripePayout(connectAccountId: $connectAccountId, payoutId: $payoutId);

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
                $request->getBody()->getContents(),
            ));

            return $this->respond($response, new ActionPayload(500));
        }

        return $this->respondWithData($response, $object);
    }
}
