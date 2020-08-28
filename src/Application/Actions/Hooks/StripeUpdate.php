<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Stripe\Event;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @return Response
 */
class StripeUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private string $webhookSecret;

    public function __construct(
        ContainerInterface $container,
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        SerializerInterface $serializer
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        // As `settings` is just an array for now, I think we have to inject Container to do this.
        $this->apiKey = $container->get('settings')['stripe']['apiKey'];
        $this->webhookSecret = $container->get('settings')['stripe']['webhookSecret'];

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $this->request->getBody(),
                $this->request->getHeaderLine('stripe-signature'),
                $this->webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError('Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if ($event instanceof Event) {
            switch ($event->type) {
                case 'charge.succeeded':
                    $this->handleChargeSucceeded($event);
                    break;
                case 'payout.paid':
                    $this->handlePayoutPaid($event);
                default:
                    $this->logger->info('Unsupported Action');
                    return $this->respond(new ActionPayload(204));
            }
        } else {
            return $this->validationError('Invalid Instance');
        }

        return $this->respondWithData($event->data->object);
    }

    public function handleChargeSucceeded(Event $event): Response
    {
        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $event->data->object->payment_intent]);

        if (!$donation) {
            $logger = 'Donation not found';
            $this->logger->info($logger);
            throw new DomainRecordNotFoundException($logger);
            return $this->respond(new ActionPayload(204));
        }
        
        // For now we support the happy success path,
        // as this is the only event type we're handling right now,
        // convert status to the one SF uses.
        if ($event->data->object->status === 'succeeded') {
            $donation->setChargeId($event->data->object->id);
            $donation->setDonationStatus('Collected');
        } else {
            return $this->validationError('Unsupported Status');
        }

        if ($donation->isReversed() && $event->data->object->metadata->matchedAmount > 0) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->entityManager->persist($donation);

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($event->data->object);
    }

    public function handlePayoutPaid(Event $event)
    {
        $stripeClient = new \Stripe\StripeClient($this->apiKey);

        $payoutId = $event->data->object->id;

        $this->logger->info('Getting all charges related to payout Id: ' . $payoutId);

        $hasMore = true;
        $lastBalanceTransactionId = null;
        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
            'type' => 'charge',
        ];

        while ($hasMore) {
            $balanceTransactions = $stripeClient->balanceTransactions->all($attributes);

            foreach ($balanceTransactions->data as $balanceTransaction) {
                $paidChargeIds[] = $balanceTransaction->source;
                $lastBalanceTransactionId = $balanceTransaction->id;
            }

            $hasMore = $balanceTransactions->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this if there's more items to iterate.
            if ($hasMore) {
                $attributes['start_after'] = $lastBalanceTransactionId;
            }
        }
        $this->logger->info('Getting all paid charge Ids complete, found: ' . sizeof($paidChargeIds));

        if (sizeof($paidChargeIds) > 0) {

            $count = 0;

            foreach ($paidChargeIds as $Id) {
                /** @var Donation $donation */
                $donation = $this->donationRepository->findOneBy(['chargeId' => $Id]);

                if ($donation) {
                    if ($donation->getDonationStatus() === 'Collected') {
                        // We're confident to set donation status to paid because this
                        // method is called only when Stripe event `payout.paid` is received.
                        $donation->setDonationStatus('Paid');

                        $this->entityManager->persist($donation);
                        $this->donationRepository->push($donation, false);

                        $count++;
                    } else {
                        $this->logger->error('Unexpected donation status found for charge Id: ' . $Id);
                    }
                } else {
                    // If a donation was not found, error log it but don't
                    // stop iterating the remaining charges.
                    if (!$donation) {
                        $this->logger->error('Donation with charge Id: ' . $Id . ' not found');
                    }
                }
            }

            $this->logger->info('Acknowledging paid donations complete, persisted: ' . $count . ' donations');
        }
    }
}
