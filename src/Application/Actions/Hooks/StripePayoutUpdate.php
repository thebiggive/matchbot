<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
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
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
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
                return $this->handlePayoutFailed($event, $response, $connectedAccountId);
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

    private function handlePayoutFailed(Event $event, Response $response, string $connectedAccountId): ResponseInterface
    {
        /**
         * @var string $payoutId
         */
        $payoutId = $event->data->object->id;

        // We still expect Stripe to pay these out later so leave donation status as-is; but want to see that the last
        // known payout status is failure.
        $donations = $this->donationRepository->findAllByPayoutId($payoutId);
        foreach ($donations as $donation) {
            $donation->recordPayoutFailed();
        }

        $charityNames = array_unique(array_map(
            static fn($donation) => $donation->getCampaign()->getCharity()->getName(),
            $donations,
        ));
        Assertion::between(
            count($charityNames),
            0,
            1,
            'Payout was unexpectedly for multiple charities: ' . implode(', ', $charityNames),
        );

        $this->entityManager->flush();

        // If the count is 0 then either payout.failed before we got payout.paid (we think possibly this can't happen),
        // or the payout dates to before May 2025 when we started saving payout IDs on donations.
        $donationCount = count($donations);

        if ($donationCount === 0) {
            $failureMessage = sprintf(
                'payout.failed for ID %s, account %s. No donations; if recent, suggests payout.paid never happened',
                $payoutId,
                $connectedAccountId,
            );
        } else {
            $charityName = $charityNames[0];

            $failureMessage = sprintf(
                'payout.failed for ID %s, account %s (%s). Ran for %d donations',
                $payoutId,
                $connectedAccountId,
                $charityName,
                $donationCount
            );
        }

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
    }
}
