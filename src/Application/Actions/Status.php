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
use MatchBot\Domain\Fund;
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
            Charity::class,
            Donation::class,
            FundingWithdrawal::class,
        ];

        // A separate ProxyGenerator with the same proxy dir and proxy namespace should produce the paths we need to
        // test for. We can't call the one inside the EM's ProxyFactory because it's private and we don't want to
        // call the public method that regenerates proxies, since in deployed ECS envs we set files to be immutable
        // and expect to generate things only in the `deploy/` entrypoints.
        $emConfig = $this->entityManager->getConfiguration();
        $proxyNamespace = $emConfig->getProxyNamespace();
        $proxyDirectory = $emConfig->getProxyDir();
        Assertion::string($proxyNamespace);
        Assertion::string($proxyDirectory);

        /**
         * @psalm-suppress DeprecatedClass
         * ProxyGenerator was deprecated here https://github.com/doctrine/common/pull/1002 because Doctrine ORM 4
         * will use Native PHP 8.4 proxies instead of any Doctrine specific proxy generator.
         */
        $proxyGenerator = new ProxyGenerator($proxyDirectory, $proxyNamespace);
        foreach ($criticalModelClasses as $modelClass) {
            $expectedFile = $proxyGenerator->getProxyFileName($modelClass);

            if (!file_exists($expectedFile)) {
                return false;
            }
        }

        return true;
    }
}
