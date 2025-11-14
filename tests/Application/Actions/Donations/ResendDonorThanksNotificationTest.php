<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\Donations\ResendDonorThanksNotification;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\EmailAddress;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Response;

class ResendDonorThanksNotificationTest extends TestCase
{
    public function testSuccess(): void
    {
        $donation = self::someDonation(
            uuid: Uuid::fromString('8c09afe8-7cb0-4a5e-af90-d3709bc38793'),
            collected: true
        );

        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
        $donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
        $notifierProphecy = $this->prophesize(DonationNotifier::class);

        $sut = new ResendDonorThanksNotification(
            $donationRepositoryProphecy->reveal(),
            $notifierProphecy->reveal(),
            $donorAccountRepositoryProphecy->reveal(),
            new NullLogger(),
        );

        $donationRepositoryProphecy->findOneByUUID($donation->getUuid())->willReturn($donation);
        $donorAccountRepositoryProphecy->accountExistsMatchingEmailWithDonation($donation)->willReturn(true);

        $notifierProphecy->notifyDonorOfDonationSuccess(
            $donation,
            false,
            true,
            EmailAddress::of('new-email@example.com'),
        )->shouldBeCalled();

        $response = $sut->__invoke(
            new ServerRequest(
                'METHOD-not-relevant-for-test-would-be-POST',
                '/uri-not-relevant-for-test',
                body: '{"sendToEmailAddress": "new-email@example.com"}'
            ),
            new Response(),
            ['donationId' => $donation->getUuid()->toString()]
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
