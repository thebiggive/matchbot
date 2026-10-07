<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\Donations\Refund;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\RefundScope;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Response;

class RefundTest extends TestCase
{
    private Donation $donation;

    /** @var ObjectProphecy<DonationRepository> $donationRepoProphecy */
    private ObjectProphecy $donationRepoProphecy;

    #[\Override]
    public function setUp(): void
    {
        $this->donation = self::someDonation(
            uuid: Uuid::fromString('8c09afe8-7cb0-4a5e-af90-d3709bc38793'),
            collected: true
        );

        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->donationRepoProphecy->findOneByUUID($this->donation->getUuid())->willReturn($this->donation);
    }

    public function testFullRefund(): void
    {
        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->refund($this->donation, RefundScope::full)
            ->shouldBeCalledOnce();

        $response = $this->buildAndInvokeAction($donationServiceProphecy, 'full');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [
                'message' => 'Refund (full) processed for Donation non-persisted 8c09afe8-7cb0-4a5e-af90-d3709bc38793 to Charity Name'
            ],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testTipRefund(): void
    {
        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy
            ->refund($this->donation, RefundScope::tip)
            ->shouldBeCalledOnce();

        $response = $this->buildAndInvokeAction($donationServiceProphecy, 'tip');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [
                'message' => 'Refund (tip) processed for Donation non-persisted 8c09afe8-7cb0-4a5e-af90-d3709bc38793 to Charity Name'
            ],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testUnsupportedScope(): void
    {
        $donationServiceProphecy = $this->prophesize(DonationService::class);
        $donationServiceProphecy->refund(Argument::any())->shouldNotBeCalled();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('aboutHalf" is not a valid backing value for enum MatchBot\Domain\RefundScope');

        $this->buildAndInvokeAction($donationServiceProphecy, 'aboutHalf');
    }

    /**
     * @param ObjectProphecy<DonationService> $donationServiceProphecy
     */
    private function buildAndInvokeAction(ObjectProphecy $donationServiceProphecy, string $scope): ResponseInterface
    {
        $sut = new Refund(
            $this->donationRepoProphecy->reveal(),
            $donationServiceProphecy->reveal(),
            new NullLogger(),
        );

        return $sut->__invoke(
            new ServerRequest(
                'METHOD-not-relevant-for-test-would-be-POST',
                '/uri-not-relevant-for-test',
                body: '{"scope": "' . $scope . '"}'
            ),
            new Response(),
            ['donationId' => $this->donation->getUuid()->toString()]
        );
    }
}
