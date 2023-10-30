<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Client\Mailer;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DonationFundsNotifier;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

class DonationFundsNotifierTest extends TestCase
{
    use ProphecyTrait;

    private Money $currentDonorBalance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentDonorBalance = Money::fromPence(17_000_00, Currency::GBP);
    }

    public function testItSendsAnEmailAboutNewDonationFunds(): void
    {
        //arrange
        $stripeCustomerId = StripeCustomerId::of('cus_1234');

        $donorAccount = new DonorAccount(
            EmailAddress::of('foo@example.com'),
            DonorName::of('Fred', 'Brooks'),
            $stripeCustomerId,
        );
        $donorAccount->setId(1); // required for logging.

        $transferAmount = Money::fromPence(52_35, Currency::GBP);

        $mailerProphecy = $this->prophesize(Mailer::class);
        $stripeProphecy = $this->prophesize(Stripe::class);
        $clockProphecy = $this->prophesize(ClockInterface::class);

        // we can't use $this inside closures as Prophecy rebinds the closures to the test doubles.
        $testObject = $this;
        $stripeProphecy->fetchBalance($stripeCustomerId, Currency::GBP)->will(function () use ($testObject) {
            return $testObject->currentDonorBalance;
        });

        $sut = new DonationFundsNotifier(
            mailer: $mailerProphecy->reveal(),
            stripe: $stripeProphecy->reveal(),
            clock: $clockProphecy->reveal(),
            logger: new NullLogger(),
        );

        $clockProphecy->sleep(seconds: 30)->will(function () use ($testObject) {
            // suppose that during this 30 seconds Stripe automatically deducts 2k from their account as they
            // had a pending tip to Big Give of 2k and it can now be completed.
            $testObject->currentDonorBalance = Money::fromPence(15_000_00, Currency::GBP);
        });

        // assert
        $mailerProphecy->sendEmail([
            'templateKey' => 'donor-funds-thanks',
            'recipientEmailAddress' => 'foo@example.com',
            'params' => [
                'donorFirstName' => 'Fred',
                'transferAmount' => "£52.35",
                'newBalance' => "£15,000.00",
            ],
        ])->shouldBeCalledOnce();

        //act
        $sut->notifyRecieptOfAccountFunds($donorAccount, $transferAmount);
    }
}
