<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\StripeClient;

/**
 * Handle charge.succeeded and charge.refunded events from a Stripe account webhook.
 *
 * @return Response
 */
abstract class Stripe extends Action
{
    protected DonationRepository $donationRepository;
    protected EntityManagerInterface $entityManager;
    protected Event $event;
    protected StripeClient $stripeClient;
    protected array $stripeSettings;

    public function __construct(
        ContainerInterface $container,
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        StripeClient $stripeClient
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->stripeClient = $stripeClient;
        $this->stripeSettings = $container->get('settings')['stripe'];

        parent::__construct($logger);
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $webhookSecret
     * @return Response|null    Validation error, or null if $this->event was set up OK.
     */
    protected function prepareEvent(ServerRequestInterface $request, string $webhookSecret): ?ResponseInterface
    {
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
            $this->logger->warning(sprintf('Skipping non-live %s webhook in Production', $this->event->type));
            return $this->respond(new ActionPayload(204));
        }

        return null;
    }
}
