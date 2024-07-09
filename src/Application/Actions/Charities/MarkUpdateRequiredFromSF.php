<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass - to be added to routes.php, need to think about authorization requirements.
 */
class MarkUpdateRequiredFromSF extends Action
{
    public function __construct(
        private CharityRepository $charityRepository,
        LoggerInterface $logger,
        private \DateTimeImmutable $now,
        private EntityManagerInterface $em,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        /** @var ?string $salesforceId */
        $salesforceId = $args['salesforceId'] ?? null;
        Assertion::nullOrString($salesforceId);

        if ($salesforceId === null) {
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $charity = $this->charityRepository->findOneBy(['salesforceId' => $salesforceId]);

        if ($charity === null) {
            throw new DomainRecordNotFoundException('Charity not found');
        }

        $charity->setUpdateRequiredFromSFSince($this->now);

        $this->em->flush();

        $data = [];

        return $this->respondWithData($response, $data, 201);
    }
}
