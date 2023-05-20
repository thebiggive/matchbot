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
use MatchBot\Domain\DonationStatus;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Response;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class UpdateHandlesLockExceptionTest extends \PHPUnit\Framework\TestCase
{
    use ProphecyTrait;

    private int $alreadyThrewTimes = 0;
    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;

    /** @var ObjectProphecy<PaymentIntentService>  */
    private ObjectProphecy $stripeIntentsProphecy;

    private StripeClient $fakeStripeClient;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $this->stripeIntentsProphecy = $this->prophesize(PaymentIntentService::class);
        $this->fakeStripeClient = $this->fakeStripeClient($this->stripeIntentsProphecy);
    }

    public function testRetriesOnUpdateStillPendingLockException(): void
    {
        // arrange
        $donationId = 'donation_id';

        $donation = $this->getDonation();

        $this->setExpectationsForPersistAfterRetry($donationId, $donation, DonationStatus::Pending);

        $this->donationRepositoryProphecy->deriveFees($donation, 'some-card-brand', 'some-country')->shouldBeCalled()->willReturn($donation);

        $updateAction = new Update(
            $this->donationRepositoryProphecy->reveal(),
            $this->entityManagerProphecy->reveal(),
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
            $this->fakeStripeClient,
            new NullLogger()
        );

        $request = new ServerRequest(method: 'PUT', uri: '', body: $this->putRequestBody(newStatus: "Pending"));

        // act
        $response = $updateAction($request, new Response(), ['donationId' => $donationId]);

        // assert
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRetriesOnUpdateToCancelledLockException(): void
    {
        // arrange
        $donationId = 'donation_id';

        $donation = $this->getDonation();

        $this->setExpectationsForPersistAfterRetry($donationId, $donation, DonationStatus::Cancelled);

        $this->donationRepositoryProphecy->push($donation, false)->shouldBeCalled()->willReturn(true);
        $this->donationRepositoryProphecy->releaseMatchFunds($donation)->shouldBeCalled();

        $updateAction = new Update(
            $this->donationRepositoryProphecy->reveal(),
            $this->entityManagerProphecy->reveal(),
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
            $this->fakeStripeClient,
            new NullLogger()
        );

        $request = new ServerRequest(method: 'PUT', uri: '', body: $this->putRequestBody(newStatus: "Cancelled"));

        // act
        $response = $updateAction($request, new Response(), ['donationId' => $donationId]);

        // assert
        $this->assertSame(200, $response->getStatusCode());
    }

    public function fakeStripeClient(ObjectProphecy $intentsProphecy): StripeClient
    {
        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentIntents = $intentsProphecy->reveal();

        return $stripeClientProphecy->reveal();
    }

    private function getDonation(): Donation
    {
        $charity = new Charity();
        $charity->setDonateLinkId('DONATE_LINK_ID');
        $charity->setName('Charity name');

        $campaign = new Campaign();
        $campaign->setIsMatched(true);
        $campaign->setCharity($charity);

        $donation = new Donation();
        $donation->createdNow();
        $donation->setDonationStatus(DonationStatus::Pending);
        $donation->setCampaign($campaign);
        $donation->setPsp('stripe');
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('1');
        $donation->setUuid(Uuid::uuid4());
        $donation->setDonorFirstName('Donor first name');
        $donation->setDonorLastName('Donor last name');
        $donation->setTransactionId('pi_dummyIntent_id');

        return $donation;
    }

    private function putRequestBody(string $newStatus): string
    {
        // props in alphabetical order
        return <<<JSON
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
          "status": "$newStatus",
          "tipAmount": null,
          "tipGiftAid": null,
          "transactionId": null
        }
        JSON;
    }

    public function setExpectationsForPersistAfterRetry(
        string $donationId,
        Donation $donation,
        DonationStatus $newStatus,
    ): void {
        $this->donationRepositoryProphecy->findAndLockOneBy(['uuid' => $donationId])
            ->will(function () use ($donation) {
                $donation->setDonationStatus(DonationStatus::Pending); // simulate loading pending donation from DB.

                return $donation;
            });

        $testCase = $this; // prophecy rebinds $this to point to the test double in the closure
        $this->entityManagerProphecy->flush()->will(function () use ($testCase) {
            if ($testCase->alreadyThrewTimes < 1) { // we could make this 3 but that would slow test down.
                $testCase->alreadyThrewTimes++;
                throw new LockWaitTimeoutException($testCase->createStub(DriverException::class), null);
            }
            return null;
        });

        // On first lock exception, EM transaction is rolled back and a new one started.
        $this->entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->entityManagerProphecy->beginTransaction()->shouldBeCalledTimes(2);
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2); // One failure, one success
        $this->entityManagerProphecy->commit()->shouldBeCalledOnce();
    }
}
