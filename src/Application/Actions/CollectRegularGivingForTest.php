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

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        if ($this->environment === Environment::Production) {
            throw new HttpNotFoundException($request);
        }

        $secret = (string)$request->getQueryParams()['secret'];
        $stream = $response->getBody();

        if (!password_verify($secret, '$2y$12$JgCEyBfFQKBrIYHs1PNobef3aMswiWPHvKX/cWHWVePEOfLHRp2Oa')) {
            $stream->write(
                'Bad or missing secret - this command is only for use by authorized people within BG test/dev environments'
            );
            return $response;
        }

        try {
            $date = new \DateTimeImmutable((string) $args['date']);
        } catch (\DateMalformedStringException $e) {
            $stream->write($e->getMessage());
            return $response;
        }

        $this->cliCommand->setLockFactory($this->lockFactory);
        $this->cliCommand->setLogger($this->logger);

        $commandTest = new CommandTester($this->cliCommand);
        $commandTest->execute(['--simulated-date' => $date->format('c')]);
        $commandOutput = $commandTest->getDisplay();

        $actualNow = new \DateTimeImmutable();

        $response =  $response->withHeader('Content-Type', 'text/plain');
        $stream->write(<<<EOF
            Big Give Matchbot
            ============================================================================================
            
            Actual current date: {$actualNow->format('D, d M Y H:i:s')}
            Running regular giving collection process with simulated timestamp {$date->format('D, d M Y H:i:s')} 
            
            
            This endpoint is only available in test/dev environments, not in production.
            
            --------------
            
            EOF
            );
        $stream->write($commandOutput);

        $stream->write("--------------\n");

        return $response;
    }
}
