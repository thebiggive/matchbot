<?php

namespace MatchBot\Application\Actions;

use GuzzleHttp\Psr7\ServerRequest;
use Laminas\Diactoros\Response;
use MatchBot\Application\Environment;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFamily;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Currency;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\IntegrationTests\IntegrationTest;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;

class SitemapTest extends TestCase
{
    public function testItGeneratesASitemap(): void
    {
        // arrange
        $campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $metaCampaignRepositoryProphecy = $this->prophesize(MetaCampaignRepository::class);

        // would like to use named params below, but apparently it doesn't work with Prophecy and PHPStan
        $campaignRepositoryProphecy->search(
            'amountRaised',
            'asc',
            0,
            100_000,
            null,
            null,
            null,
            [],
            null,
        )->willReturn([
            self::someCampaign(sfId: Salesforce18Id::ofCampaign('000000000000000000')),
        ]);

        $metaCampaignRepositoryProphecy->allNonHidden()->willReturn([
            $this->someMetaCampaign(false, false, slug: MetaCampaignSlug::of('this-is-the-metacampaign-slug')),
        ]);

        $sut = new Sitemap(
            $campaignRepositoryProphecy->reveal(),
            $metaCampaignRepositoryProphecy->reveal(),
            Environment::Test,
            new \DateTimeImmutable('2025-01-01'),
            new NullLogger(),
        );

        // act
        $response = $sut(new ServerRequest('GET', 'url'), new Response(), []);

        // assert
        $response->getBody()->rewind();
        $content = $response->getBody()->getContents();

        $this->assertXmlStringEqualsXmlString(
            <<<'XML'
            <?xml version="1.0"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url>
                    <loc>http://example.com/campaign/000000000000000000</loc>
                    <changeFreq>hourly</changeFreq>
                    <priority>0.5</priority>
                </url>
                <url>
                    <loc>http://example.com/donate/000000000000000000</loc>
                    <changeFreq>hourly</changeFreq>
                    <priority>0.5</priority>
                </url>
                <url>
                    <loc>http://example.com/this-is-the-metacampaign-slug</loc>
                    <changeFreq>monthly</changeFreq>
                    <priority>0.5</priority>
                </url>
            </urlset> 
            XML,
            $content
        );
    }
}
