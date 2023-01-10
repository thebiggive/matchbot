<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\Donations\Update;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Response;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClientInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class UpdateHandlesLockExceptionTest extends \PHPUnit\Framework\TestCase
{
    use ProphecyTrait;

    private int $alreadyThrewTimes = 0;

    public function testFailsOnCancellationLockException(): void
    {
        // arrange
        $donationId = 'donation_id';

        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $stripeIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripeIntentsProphecy->reveal());

        $donation = $this->getDonation();

        $donationRepositoryProphecy->findAndLockOneBy(['uuid' => $donationId])->willReturn($donation);

        $donationRepositoryProphecy->push($donation, false)
            ->willThrow(new LockWaitTimeoutException($this->createStub(DriverException::class), null));

        $updateAction = new Update(
            $donationRepositoryProphecy->reveal(),
            $entityManagerProphecy->reveal(),
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
            $fakeStripeClient,
            new NullLogger()
        );

        $request = new ServerRequest(method: 'PUT', uri: '', body: '{"status": "Cancelled"}');

        // act
        $response = $updateAction($request, new Response(), ['donationId' => $donationId]);

        // assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            <<<'JSON'
              {
                "error": {
                  "type": "BAD_REQUEST",
                  "description": "Cancellation was not possible due to another action at the same time affecting this donation"
                }
              }
            JSON
            ,
            (string)$response->getBody()
        );
    }

    public function testRetriesOnUpdateLockException(): void
    {
        // arrange
        $donationId = 'donation_id';

        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $stripeIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripeIntentsProphecy->reveal());

        $donation = $this->getDonation();

        $donationRepositoryProphecy->findAndLockOneBy(['uuid' => $donationId])->willReturn($donation);
        $donationRepositoryProphecy->deriveFees($donation, 'some-card-brand', 'some-country')->shouldBeCalled()->willReturn($donation);


        $updateAction = new Update(
            $donationRepositoryProphecy->reveal(),
            $entityManagerProphecy->reveal(),
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
            $fakeStripeClient,
            new NullLogger()
        );

        $request = new ServerRequest(method: 'PUT', uri: '', body: $this->putRequestBody());

        $testCase = $this; // prophecy rebinds $this to point to the test double in the closure
        $entityManagerProphecy->flush()->will(function () use ($testCase) {
            if ($testCase->alreadyThrewTimes < 1) { // we could make this 3 but that would slow test down.
                $testCase->alreadyThrewTimes++;
                throw new LockWaitTimeoutException($testCase->createStub(DriverException::class), null);
            }
            return null;
        });

        $entityManagerProphecy->beginTransaction()->shouldBeCalled();
        $entityManagerProphecy->refresh($donation, LockMode::PESSIMISTIC_WRITE)->shouldBeCalled();
        $entityManagerProphecy->persist($donation)->shouldBeCalled();
        $entityManagerProphecy->commit()->shouldBeCalled();

        // act
        $response = $updateAction($request, new Response(), ['donationId' => $donationId]);

        // assert
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * We can't use prophecy for this because we need a public property, which Prophecy does not support
     * See https://github.com/phpspec/prophecy/issues/86
     *
     * It's mabye that the Stripe API requires us to use a public property of their object.
     */
    public function fakeStripeClient(PaymentIntentService $intents): StripeClientInterface
    {
        $stripeClient = new class implements StripeClientInterface {
            public function getApiKey()
            {
                throw new \Exception('Not implemented');
            }

            public function getClientId()
            {
                throw new \Exception('Not implemented');
            }

            public function getApiBase()
            {
                throw new \Exception('Not implemented');
            }

            public function getConnectBase()
            {
                throw new \Exception('Not implemented');
            }

            public function getFilesBase()
            {
                throw new \Exception('Not implemented');
            }

            public function request($method, $path, $params, $opts)
            {
                throw new \Exception('Not implemented');
            }

            public mixed $paymentIntents;
        };

        $stripeClient->paymentIntents = $intents;

        return $stripeClient;
    }

    public function getDonation(): Donation
    {
        $charity = new Charity();
        $charity->setDonateLinkId('DONATE_LINK_ID');
        $charity->setName('Charity name');

        $campaign = new Campaign();
        $campaign->setIsMatched(true);
        $campaign->setCharity($charity);

        $donation = new Donation();
        $donation->createdNow();
        $donation->setDonationStatus('Pending');
        $donation->setCampaign($campaign);
        $donation->setPsp('stripe');
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('1');
        $donation->setUuid(Uuid::uuid4());
        $donation->setDonationStatus('Pending');
        $donation->setDonorFirstName('Donor first name');
        $donation->setDonorLastName('Donor last name');

        return $donation;
    }

    private function putRequestBody(): string
    {
        // props in alphabetical order
        return <<<'JSON'
        {
          "autoConfirmFromCashBalance": false,
          "billingPostalAddress": null,
          "cardBrand": "some-card-brand",
          "cardCountry": "some-country",
          "countryCode": null,
          "creationRecaptchaCode": null,
          "currencyCode": null,
          "donationAmount": "1",
          "emailAddress": null,
          "feeCoverAmount": null,
          "firstName": null,
          "giftAid": false,
          "homeAddress": null,
          "homePostcode": null,
          "lastName": null,
          "optInChampionEmail": false,
          "optInCharityEmail": false,
          "optInTbgEmail": false,
          "status": "Pending",
          "tipAmount": null,
          "tipGiftAid": null,
          "transactionId": null
        }
        JSON;
    }
}