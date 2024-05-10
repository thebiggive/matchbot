<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\Pledge;
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
    protected function action(Request $request, Response $response, array $args): Response
    {
        $errorMessage = null;

        if ($this->redis === null || !$this->redis->isConnected()) {
            $errorMessage = 'Redis not connected';
        }

        try {
            $connection = $this->entityManager->getConnection();
            $gotDbConnection = $connection->isConnected() || $connection->connect();
            if (!$gotDbConnection) {
                $errorMessage = 'Database not connected';
            }
        } catch (DBALException $exception) {
            $errorMessage = 'Database connection failed';
        }

        if (!$this->checkCriticalModelDoctrineProxies()) {
            $errorMessage = 'Doctrine proxies not built';
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

    /**
     * @return bool Whether all needed proxies are present.
     */
    private function checkCriticalModelDoctrineProxies(): bool
    {
        // Concrete, core mapped app models only.
        $criticalModelClasses = [
            Campaign::class,
            CampaignFunding::class,
            ChampionFund::class,
            Charity::class,
            Donation::class,
            FundingWithdrawal::class,
            Pledge::class,
        ];

        // A separate ProxyGenerator with the same proxy dir and proxy namespace should produce the paths we need to
        // test for. We can't call the one inside the EM's ProxyFactory because it's private and we don't want to
        // call the public method that regenerates proxies, since in deployed ECS envs we set files to be immutable
        // and expect to generate things only in the `deploy/` entrypoints.
        $emConfig = $this->entityManager->getConfiguration();
        $proxyGenerator = new ProxyGenerator($emConfig->getProxyDir(), $emConfig->getProxyNamespace());
        foreach ($criticalModelClasses as $modelClass) {
            $expectedFile = $proxyGenerator->getProxyFileName($modelClass);

            if (!file_exists($expectedFile)) {
                return false;
            }
        }

        return true;
    }
}
