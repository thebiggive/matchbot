<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

class CreateTest extends TestCase
{
    public function testDeserialiseError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{"not-good-json';

        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Donation Create data deserialise error',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testModelError(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(new DonationCreate()) // empty DonationCreate == {} deserialised.
            ->willThrow(new UnexpectedValueException('Required boolean fields not set'));

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = '{}'; // Valid JSON but `buildFromApiRequest()` will error
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Required boolean fields not set',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCampaignClosed(): void
    {
        $donation = $this->getTestDonation(false, false);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Campaign 123CampaignId is not open',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testValidDataForMatchedCampaignWhenFundLockNotAcquired(): void
    {
        $donation = $this->getTestDonation(true, true);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->shouldNotBeCalled();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))
            ->willThrow(DomainLockContentionException::class)
            ->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(503, ['error' => [
            'type' => 'SERVER_ERROR',
            'description' => 'Fund resource locked',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
        $this->assertEquals(503, $response->getStatusCode());
    }

    public function testSuccessWithMatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, true);

        $fundingWithdrawalForMatch = new FundingWithdrawal();
        $fundingWithdrawalForMatch->setAmount('8.00'); // Partial match
        $fundingWithdrawalForMatch->setDonation($donation);

        $donationToReturn = $donation;
        $donationToReturn->setDonationStatus('Pending');
        $donationToReturn->addFundingWithdrawal($fundingWithdrawalForMatch);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donationToReturn);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldBeCalledOnce();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(12, $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertEquals(8, $payloadArray['donation']['matchReservedAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
        $this->assertEquals('Pending', $payloadArray['donation']['status']);
    }

    public function testSuccessWithUnmatchedCampaign(): void
    {
        $donation = $this->getTestDonation(true, false);

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy
            ->buildFromApiRequest(Argument::type(DonationCreate::class))
            ->willReturn($donation);
        $donationRepoProphecy->push(Argument::type(Donation::class), true)->willReturn(true)->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledOnce();
        $entityManagerProphecy->flush()->shouldBeCalledOnce();

        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());

        $data = json_encode($donation->toApiModel(true));
        $request = $this->createRequest('POST', '/v1/donations', $data);
        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);

        $this->assertIsString($payloadArray['jwt']);
        $this->assertNotEmpty($payloadArray['jwt']);
        $this->assertIsArray($payloadArray['donation']);
        $this->assertFalse($payloadArray['donation']['giftAid']);
        $this->assertEquals(12, $payloadArray['donation']['donationAmount']);
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $payloadArray['donation']['donationId']);
        $this->assertEquals(0, $payloadArray['donation']['matchReservedAmount']);
        $this->assertEquals('567CharitySFID', $payloadArray['donation']['charityId']);
        $this->assertEquals('123CampaignId', $payloadArray['donation']['projectId']);
    }

    private function getTestDonation(bool $campaignOpen, bool $campaignMatched): Donation
    {
        $charity = new Charity();
        $charity->setDonateLinkId('567CharitySFID');
        $charity->setName('Create test charity');

        $campaign = new Campaign();
        $campaign->setCharity($charity);
        $campaign->setIsMatched($campaignMatched);
        $campaign->setSalesforceId('123CampaignId');
        $campaign->setStartDate((new \DateTime())->sub(new \DateInterval('P2D')));
        if ($campaignOpen) {
            $campaign->setEndDate((new \DateTime())->add(new \DateInterval('P1D')));
        } else {
            $campaign->setEndDate((new \DateTime())->sub(new \DateInterval('P1D')));
        }

        $donation = new Donation();
        $donation->setAmount('12.00');
        $donation->setCampaign($campaign);
        $donation->setCharityComms(false);
        $donation->setGiftAid(false);
        $donation->setTbgComms(false);
        $donation->setUuid(Uuid::fromString('12345678-1234-1234-1234-1234567890ab'));

        return $donation;
    }
}
