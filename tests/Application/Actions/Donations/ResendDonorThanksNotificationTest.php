<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations\ResendDonorThanks;

use DI\Container;
use GuzzleHttp\Psr7\ServerRequest;
use Laminas\Diactoros\Request;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Actions\Donations\ResendDonorThanksNotification;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;
use Slim\Routing\Route;

class ResendDonorThanksNotificationTest extends TestCase
{
    public function testSuccess(): void
    {
        $donation = self::someDonation(
            uuid: Uuid::fromString('8c09afe8-7cb0-4a5e-af90-d3709bc38793'),
            collected: true
        );

        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $notifierProphecy = $this->prophesize(DonationNotifier::class);

        $sut = new ResendDonorThanksNotification(
            $donationRepositoryProphecy->reveal(),
            $notifierProphecy->reveal(),
            new NullLogger(),
        );

        $donationRepositoryProphecy->findOneByUUID($donation->getUuid())->willReturn($donation);

        $notifierProphecy->notifyDonorOfDonationSuccess($donation)->shouldBeCalled();

        $response = $sut->__invoke(
            new ServerRequest('POST', '/'),
            new Response(),
            ['donationId' => $donation->getUUID()->toString()]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [
                'message' => 'Notification sent to donor for Donation non-persisted 8c09afe8-7cb0-4a5e-af90-d3709bc38793 to Charity Name'
            ],
            \json_decode($response->getBody()->getContents(), true)
        );
    }
}
