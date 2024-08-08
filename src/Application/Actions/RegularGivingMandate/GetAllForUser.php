<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use DateTimeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Environment;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class GetAllForUser extends Action
{
    public function __construct(
        private Environment $environment,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        $donorId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert($donorId instanceof PersonId);

        return new JsonResponse(['mandates' => [
            [
                'id' => 'e552a93e-540e-11ef-98b2-3b7275661822',
                'donorId' => $donorId->value,
                'amount' => Money::fromPoundsGBP(6),
                'campaignId' => Salesforce18Id::of('DummySFIDCampaign0'),
                'charityId' => Salesforce18Id::of('DummySFIDCharity00'),
                'schedule' => [
                    'type' => 'monthly',
                    'dayOfMonth' => 31,
                    'activeFrom' => (new \DateTimeImmutable('2024-08-06'))->format(DateTimeInterface::ATOM),
                ],
                'charityName' => 'Some Charity',
                'createdTime' => (new \DateTimeImmutable('2024-08-06'))->format(DateTimeInterface::ATOM),
                'giftAid' => true,
                'status' => 'active',
                'tipAmount' => Money::fromPoundsGBP(1),
                'updatedTime' => (new \DateTimeImmutable('2024-08-06'))->format(DateTimeInterface::ATOM),
            ]
        ]]);
    }
}
