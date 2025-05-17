<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Application\Messenger\Handler\CharityUpdatedHandler;
use MatchBot\Client;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class PullCharityUpdatedBasedOnSfHookTest extends IntegrationTest
{
    #[\Override]
    public function tearDown(): void
    {
        $this->getContainer()->set(Client\Fund::class, null);
        $this->getContainer()->set(Client\Campaign::class, null);
    }

    public function testItPullsCharityUpdateAfterSalesforceSendsHook(): void
    {
        // arrange
        $em = $this->getService(EntityManagerInterface::class);

        $campaign = TestCase::someCampaign();
        $charity = $campaign->getCharity();
        $sfId = $charity->getSalesforceId();

        $em->persist($campaign);
        $em->persist($charity);
        $em->flush();

        $campaignClientProphecy = $this->prophesize(Client\Campaign::class);

        $campaignClientProphecy->getById($campaign->getSalesforceId(), withCache: false)->willReturn(
            $this->simulatedCampaignFromSFAPI(
                $sfId,
                'New Charity Name',
                $charity->getStripeAccountId() ?? throw new \Exception('Missing Stripe ID')
            )
        );

        $charitySfId = Salesforce18Id::ofCharity($sfId);

        // Ensure we're using stubbed client before `CharityUpdatedHandler` makes its campaign repo.
        $this->getContainer()->set(Client\Campaign::class, $campaignClientProphecy->reveal());

        $messageHandler = $this->getService(CharityUpdatedHandler::class);

        $busProphecy = $this->prophesize(MessageBusInterface::class);
        $busProphecy->dispatch(Argument::type(Envelope::class))
            ->will(
                /**
                 * @param array{0: Envelope} $args
                 */
                function (array $args) use ($charitySfId, $messageHandler): Envelope {
                    $envelope = $args[0];
                    /** @var CharityUpdated $message */
                    $message = $envelope->getMessage();
                    TestCase::assertInstanceOf(CharityUpdated::class, $message);
                    TestCase::assertSame($charitySfId->value, $message->charityAccountId->value);

                    // Simulate message processing
                    $messageHandler($message);

                    return $envelope;
                }
            )
            ->shouldBeCalledOnce();

        $this->getContainer()->set(MessageBusInterface::class, $busProphecy->reveal());

        // act
        $this->simulateRequestFromSFTo('/hooks/charities/' . $sfId . '/update-required');

        // assert
        $em->clear();

        $charity = $this->getService(CharityRepository::class)->findOneBySfIDOrThrow(Salesforce18Id::ofCharity($sfId));
        $this->assertSame('New Charity Name', $charity->getName());
        $this->assertFalse($campaign->isReady());
        $this->assertSame('Preview', $campaign->getStatus());
    }

    /**
     * @return array<string, mixed>
     */
    private function simulatedCampaignFromSFAPI(string $sfId, string $newCharityName, string $stripeAccountId): array
    {
        return [
            'currencyCode' => 'GBP',
            'endDate' => '2020-01-01',
            'startDate' => '2020-01-01',
            'isMatched' => true,
            'title' => 'Campaign title not relavent',
            'status' => 'Preview',
            'ready' => false,
            'thankYouMessage' => 'Test thank you message',
            'charity' => [
                'id' => $sfId,
                'name' => $newCharityName,
                'website' => 'https://example.com',
                'logoUri' => 'https://biggive.example.com/logo.png',
                'stripeAccountId' => $stripeAccountId,
                'giftAidOnboardingStatus' => 'Onboarded',
                'hmrcReferenceNumber' => null,
                'phoneNumber' => '020 1234 1234',
                'postalAddress' => [
                    'line1' => 'example address line 1',
                    'line2' => 'example address line 1',
                    'city' => 'some city',
                    'postalCode' => 'some postalCode',
                    'country' => 'some country',
                ],
                'regulatorRegion' => 'England and Wales',
                'regulatorNumber' => null,
            ],
        ];
    }

    private function simulateRequestFromSFTo(string $URI): void
    {
        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        $body = 'body is ignored';

        $this->getApp()->handle(TestCase::createRequest(
            method: 'POST',
            path: $URI,
            bodyString: $body,
            headers: ['x-send-verify-hash' => hash_hmac('sha256', $body, $salesforceSecretKey)]
        ));
    }
}
