<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Cancel all donations to a specified campaign, by a specific payment method type, for the current Donor.
 */
class CancelAll extends Action
{
    #[Pure]
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $campaignId = $request->getQueryParams()['campaignId'] ?? null;
        $paymentMethodTypeParam = $request->getQueryParams()['paymentMethodType'] ?? null;

        if (!is_string($campaignId) || !is_string($paymentMethodTypeParam)) {
            throw new BadRequestException('Missing campaign ID or payment method type');
        }

        $campaign = Salesforce18Id::ofCampaign($campaignId);
        $paymentMethodType = PaymentMethodType::from($paymentMethodTypeParam);
        $donorStripeId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($donorStripeId));

        $uuids = $this->donationRepository->findPendingByDonorCampaignAndMethod(
            $donorStripeId,
            $campaign,
            $paymentMethodType,
        );

        $donations = [];
        foreach ($uuids as $uuid) {
            $donation = $this->entityManager->wrapInTransaction(function () use ($uuid): Donation {
                $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $uuid->toString()]);
                Assertion::notNull($donation);
                $this->donationService->cancel($donation);

                return $donation;
            });


            Assertion::isInstanceOf($donation, Donation::class);

            $donations[] = $donation;
        }

        return $this->respondWithData($response, [
            'donations' => array_map(static fn(Donation $d) => $d->toFrontEndApiModel(), $donations),
        ]);
    }
}
