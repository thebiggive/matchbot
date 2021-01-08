<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Redis;

class Status extends Action
{
    #[Pure]
    public function __construct(
        private EntityManagerInterface $entityManager,
        protected LoggerInterface $logger,
        private ?Redis $redis
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        /** @var string|null $errorMessage */
        $errorMessage = null;

        if ($this->redis === null || !$this->redis->isConnected()) {
            $errorMessage = 'Redis not connected';
        }

        try {
            $gotDbConnection = (
                $this->entityManager->getConnection()->isConnected() ||
                $this->entityManager->getConnection()->connect()
            );
            if (!$gotDbConnection) {
                $errorMessage = 'Database not connected';
            }
        } catch (DBALException $exception) {
            $errorMessage = 'Database connection failed';
        }

        if ($errorMessage === null) {
            return $this->respondWithData(['status' => 'OK']);
        }

        $error = new ActionError(ActionError::SERVER_ERROR, $errorMessage);

        return $this->respond(new ActionPayload(500, ['error' => $errorMessage], $error));
    }
}
