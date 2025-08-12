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

class Sitemap extends Action
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
        $sitemap = \Spatie\Sitemap\Sitemap::create()
            ->add('hello')
            ->render();

        $response->getBody()->write($sitemap);
        return $response;
    }
}
