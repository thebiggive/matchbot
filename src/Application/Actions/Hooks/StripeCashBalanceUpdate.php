<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Notifier\StripeChatterInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

class StripeCashBalanceUpdate extends Stripe
{
    private ChatterInterface $chatter;

    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger,
    ) {
        /**
         * @var ChatterInterface $chatter
         * Injecting `StripeChatterInterface` directly doesn't work because `Chatter` itself
         * is final and does not implement our custom interface.
         */
        $chatter = $container->get(StripeChatterInterface::class);
        $this->chatter = $chatter;

        parent::__construct($container, $logger);
    }

    /**
     * @return Response
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $request,
            $this->stripeSettings['accountWebhookSecret'],
            true,
            $response,
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $event = $this->event ?? throw new \RuntimeException("Stripe event not set");

        $this->logger->info(sprintf(
            'Received Stripe Connect app event type "%s" on account %s',
            $event->type,
            $event->account,
        ));

        if (getenv('APP_ENV') === 'production') {
            return $this->respond($response, new ActionPayload(200));
        } else {
            // for now, and outside of production, just send the webhook to slack so we can see if we like it.
            $this->chatter->send(new ChatMessage(
                "StripeCashBalanceUpdate in development - recieved webhook from stripe: \n\n" .
                $event->toJSON()
            ));
            return $this->respond($response, new ActionPayload(200));
        }
    }
}
