<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Environment;
use MatchBot\Domain\CampaignRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Safe\DateTimeImmutable;
use SimpleXMLElement;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Creates a sitemap for the Big Give public facing site. See spec at https://www.sitemaps.org/protocol.html#prioritydef
 */
class Sitemap extends Action
{
    #[Pure]
    public function __construct(
        private CacheInterface $cache,
        private CampaignRepository $campaignRepository,
        private Environment $environment,
        private \DateTimeImmutable $now,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        // initial implementation of sitemap - following spec from https://www.sitemaps.org/protocol.html#prioritydef
        // which is what Google uses.

        // For now just listing up to 100k campaign and (if open) donate pages.

        // @todo:
        // - add charity pages as well as campaigns.
        // - add metacampaigns
        // - add some of the most important other pages, e.g. static content. But afaik its not essential for
        //   a sitemap to be comprehensive to be useful
        // - consider if the 100k limit is appropriate.
        // - increase cache duration, maybe explicitly expire cache when content changes. Consider persisting
        // - something that would be cheaper to access than this to make additions to when we add a campaign.
        // - add integration test
        // - submit sitemap to search engines and/or list in robots.txt
        $sitemapXml = $this->cache->get('sitemap', function (ItemInterface $cacheItem) {
            $cacheItem->expiresAfter(60 * 10); // ten minutes

            $xml = new SimpleXMLElement('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
            $campaigns = $this->campaignRepository->search(
                sortField: 'amountRaised',
                sortDirection:  'asc',
                offset: 0,
                limit: 100_000,
                status: null,
                metaCampaignSlug: null,
                fundSlug: null,
                jsonMatchInListConditions: [],
                term:null,
            );

            foreach ($campaigns as $campaign) {
                $endsInFuture = $campaign->getEndDate() > $this->now;

                $changeFreq = $endsInFuture ? 'daily' : 'monthly';
                if ($campaign->isOpen($this->now)) {
                    $changeFreq = 'hourly';
                }

                $this->addUrl(
                    xml: $xml,
                    url: $this->environment->publicDonateURLPrefix() . 'campaign/' . $campaign->getSalesforceId(),
                    updatedAt: DateTimeImmutable::createFromInterface($campaign->getUpdatedDate()),
                    changeFreq: $changeFreq,
                    priority: $endsInFuture ? '0.5' : '0.25',
                );

                if ($campaign->isOpen($this->now) && ! $campaign->isRegularGiving()) {
                    // regular giving donate pages are at a different address and in any case behind a login-wall,
                    // so not worth listing in sitemap.
                    $this->addUrl(
                        xml: $xml,
                        url: $this->environment->publicDonateURLPrefix() . 'donate/' . $campaign->getSalesforceId(),
                        updatedAt: DateTimeImmutable::createFromInterface($campaign->getUpdatedDate()),
                        changeFreq: $changeFreq,
                        priority: '0.5'
                    );
                }
            }

            return $xml->asXML();
        });

        \assert(\is_string($sitemapXml));

        $response->getBody()->write($sitemapXml);

        return $response->withHeader('Content-Type', 'application/xml');
    }

    private function addUrl(SimpleXMLElement $xml, string $url, \DateTimeImmutable $updatedAt, string $changeFreq, string $priority): void
    {
        $urlElement = $xml->addChild('url') ?? throw new \LogicException('Expected child element not returned');

        $urlElement->addChild('loc', $url);
        $urlElement->addChild('lastmod', $updatedAt->format('c'));
        $urlElement->addChild('changeFreq', $changeFreq); // options: always, hourly, daily, weekly, monthly, yearly, never
        $urlElement->addChild('priority', $priority); // value betweeon 0 and 1 sets relative priority to other content on same site.
    }
}
