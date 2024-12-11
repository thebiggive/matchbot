<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\RegularGivingMandate;

use MatchBot\Application\Environment;
use MatchBot\Domain\RegularGivingMandateRepository;
use JetBrains\PhpStorm\Pure;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private readonly RegularGivingMandateRepository $regularGivingMandateRepository,
        private readonly Environment                    $environment,
        LoggerInterface                                 $logger
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }
        if (empty($args['mandateId'])) {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $donorId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert($donorId instanceof PersonId);

        $mandate = $this->regularGivingMandateRepository->findOneByUuid(uuid: (string) $args['mandateId']);

        if (!$mandate) {
            throw new DomainRecordNotFoundException('Mandate not found');
        }

        return new JsonResponse([
            'mandate' => $mandate
        ]);
    }
}
