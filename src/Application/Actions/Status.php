<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\FundingWithdrawal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Redis;

class Status extends Action
{
    #[Pure]
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?Redis $redis,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        /** @var string|null $errorMessage */
        $errorMessage = null;

        if ($this->redis === null || !$this->redis->isConnected()) {
            $errorMessage = 'Redis not connected';
        }

        try {
            $connection = $this->entityManager->getConnection();

            // dummy query just to force DB connection to be made, since
            // \Doctrine\DBAL\Connection::connect is marked @internal and will be protected in DBAL 4
            $connection->executeQuery('SELECT 1');
            $gotDbConnection = $connection->isConnected();
            if (!$gotDbConnection) {
                $errorMessage = 'Database not connected';
            }
        } catch (DBALException) {
            $errorMessage = 'Database connection failed';
        }

        if ($errorMessage === null) {
            if (($request->getQueryParams()['ping'] ?? null) === 'ping') {
                return $this->respondWithData($response, ['pong']);
            }

            return $this->respondWithData($response, ['status' => 'OK']);
        }

        $error = new ActionError(ActionError::SERVER_ERROR, $errorMessage);

        return $this->respond($response, new ActionPayload(500, ['error' => $errorMessage], $error));
    }
}
