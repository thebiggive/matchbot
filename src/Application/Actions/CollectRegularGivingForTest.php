<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Commands\TakeRegularGivingDonations;
use MatchBot\Application\Environment;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

/**
 * To test on dev machine, send GET request to e.g.
 * http://localhost:30030/v1/test-donation-collection-for-date/
 */
class CollectRegularGivingForTest extends Action
{
    #[Pure]
    public function __construct(
        LoggerInterface $logger,
        private TakeRegularGivingDonations $cliCommand,
        private LockFactory $lockFactory,
        private Environment $environment,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        if ($this->environment === Environment::Production) {
            throw new HttpNotFoundException($request);
        }

        $date = $args['date'];

        $this->cliCommand->setLockFactory($this->lockFactory);
        $this->cliCommand->setLogger($this->logger);

        $commandTest = new CommandTester($this->cliCommand);
        $commandTest->execute(['--simulated-date'=> $date]);
        $commandOutput = $commandTest->getDisplay();

        $response =  $response->withHeader('Content-Type', 'text/plain');
        $response->getBody()->write($commandOutput);

        return $response;
    }
}
