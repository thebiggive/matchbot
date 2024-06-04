<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Stripe\Exception\PermissionException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

class DonationServiceTest extends TestCase
{
    private DonationService $sut;

    /** @var \Prophecy\Prophecy\ObjectProphecy<Stripe> */
    private \Prophecy\Prophecy\ObjectProphecy $stripeProphecy;

    /** @var \Prophecy\Prophecy\ObjectProphecy<DonationRepository> */
    private \Prophecy\Prophecy\ObjectProphecy $donationRepoProphecy;

    /** @var ObjectProphecy<StripeChatterInterface> */
    private ObjectProphecy $chatterProphecy;

    public function setUp(): void
    {
        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $this->chatterProphecy = $this->prophesize(StripeChatterInterface::class);

        $this->sut = new DonationService(
            $this->donationRepoProphecy->reveal(),
            $this->prophesize(CampaignRepository::class)->reveal(),
            new NullLogger(),
            $this->prophesize(RetrySafeEntityManager::class)->reveal(),
            $this->stripeProphecy->reveal(),
            $this->prophesize(Adapter::class)->reveal(),
            $this->chatterProphecy->reveal(),
            $this->prophesize(ClockInterface::class)->reveal(),
        );
    }

    public function testIdentifiesCharityLackingCapabilities(): void
    {
        $customerId = 'CUSTOMER_ID';

        $donationCreate = new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: 'projectIDxxxxxxxxx',
            psp: 'stripe',
            pspCustomerId: $customerId
        );

        $donation = Donation::fromApiModel(
            $donationCreate,
            TestCase::someCampaign(stripeAccountId: 'STRIPE-ACCOUNT-ID')
        );

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate)->willReturn($donation);

        $this->chatterProphecy->send(
            new ChatMessage(
                "[test] Stripe Payment Intent create error on {$donation->getUuid()}" .
                ', unknown [Stripe\Exception\PermissionException]: ' .
                'Your destination account needs to have at least one of the following capabilities enabled: ' .
                'transfers, crypto_transfers, legacy_payments. Charity: ' .
                'Charity Name [STRIPE-ACCOUNT-ID].'
            )
        )->shouldBeCalledOnce();

        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willThrow(new PermissionException(
                'Your destination account needs to have at least one of the following capabilities ' .
                'enabled: transfers, crypto_transfers, legacy_payments'
            ));

        $this->expectException(CharityAccountLacksNeededCapaiblities::class);

        $this->sut->createDonation($donationCreate, $customerId);
    }
}