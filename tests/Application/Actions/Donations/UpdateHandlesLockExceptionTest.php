<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\Donations\Update;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorName;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Response;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class UpdateHandlesLockExceptionTest extends TestCase
{
    use ProphecyTrait;

    private int $alreadyThrewTimes = 0;
    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
    }

    public function testRetriesOnUpdateStillPendingLockException(): void
    {
        // arrange
        $donationId = 'donation_id';

        $donation = $this->getDonation();

        $this->setExpectationsForPersistAfterRetry($donationId, $donation, DonationStatus::Pending);

        $updateAction = $this->makeUpdateAction();

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

        $this->donationRepositoryProphecy->releaseMatchFunds($donation)->shouldBeCalled();

        $updateAction = $this->makeUpdateAction();

        $request = new ServerRequest(method: 'PUT', uri: '', body: $this->putRequestBody(newStatus: "Cancelled"));

        // act
        $response = $updateAction($request, new Response(), ['donationId' => $donationId]);

        // assert
        $this->assertSame(200, $response->getStatusCode());
    }

    private function getDonation(): Donation
    {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setSalesforceId('DONATE_LINK_ID');
        $charity->setName('Charity name');

        $campaign = new Campaign(charity: $charity);
        $campaign->setIsMatched(true);

        $donation = Donation::emptyTestDonation('1');
        $donation->createdNow();
        $donation->setDonationStatus(DonationStatus::Pending);
        $donation->setCampaign($campaign);
        $donation->setUuid(Uuid::uuid4());
        $donation->setDonorName(DonorName::of('Donor first name', 'Donor last name'));
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
          "countryCode": null,
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
        $this->donationRepositoryProphecy->findAndLockOneByUUID($donationId)
            ->will(function () use ($donation) {
                $donation->setDonationStatus(DonationStatus::Pending); // simulate loading pending donation from DB.

                return $donation;
            });

        $testCase = $this; // prophecy rebinds $this to point to the test double in the closure
        $this->entityManagerProphecy->flush()->will(function () use ($donation, $newStatus, $testCase) {
            if ($testCase->alreadyThrewTimes < 1) { // we could make this 3 but that would slow test down.
                $testCase->alreadyThrewTimes++;
                throw new LockWaitTimeoutException($testCase->createStub(DriverException::class), null);
            }

            TestCase::assertEquals($newStatus, $donation->getDonationStatus());
            return null;
        });

        // On first lock exception, EM transaction is rolled back and a new one started.
        $this->entityManagerProphecy->rollback()->shouldBeCalledOnce();

        $this->entityManagerProphecy->beginTransaction()->shouldBeCalledTimes(2);
        $this->entityManagerProphecy->persist(Argument::type(Donation::class))
            ->shouldBeCalledTimes(2); // One failure, one success
        $this->entityManagerProphecy->commit()->shouldBeCalledOnce();
    }

    public function makeUpdateAction(): Update
    {
        $routableMessageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $routableMessageBusProphecy->dispatch(Argument::type(Envelope::class))->willReturnArgument();

        return new Update(
            $this->donationRepositoryProphecy->reveal(),
            $this->entityManagerProphecy->reveal(),
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
            $this->createStub(Stripe::class),
            new NullLogger(),
            new MockClock(),
            $routableMessageBusProphecy->reveal(),
        );
    }
}
