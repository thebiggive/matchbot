<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;

/**
 * Handle charge.succeeded and charge.refunded events from a Stripe account webhook.
 *
 * @return Response
 */
abstract class Stripe extends Action
{
    protected Event $event;
    protected array $stripeSettings;

    public function __construct(ContainerInterface $container, LoggerInterface $logger)
    {
        $this->stripeSettings = $container->get('settings')['stripe'];

        parent::__construct($logger);
    }

    /**
     * @param ServerRequestInterface    $request
     * @param string                    $webhookSecret
     * @param bool                      $connect        Whether event is a Connect one.
     * @return Response|null            Validation error or no-op 204 response if event should
     *                                  not be processed, or null if $this->event was set up.
     */
    protected function prepareEvent(
        ServerRequestInterface $request,
        string $webhookSecret,
        bool $connect
    ): ?ResponseInterface {
        try {
            $this->event = \Stripe\Webhook::constructEvent(
                $request->getBody(),
                $request->getHeaderLine('stripe-signature'),
                $webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError("Invalid Payload: {$e->getMessage()}", 'Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if (!($this->event instanceof Event)) {
            return $this->validationError('Invalid event');
        }

        if (!$this->event->livemode && getenv('APP_ENV') === 'production') {
            /**
             * This is normal for Connect events so just `info()` log it in that case.
             * "...both live and test webhooks will be sent to your production webhook URLs."
             * @link https://stripe.com/docs/connect/webhooks
             */
            $method = $connect ? 'info' : 'warning';
            $this->logger->$method(sprintf('Skipping non-live %s webhook in Production', $this->event->type));

            return $this->respond(new ActionPayload(204));
        }

        return null;
    }
}
