<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Client\Mailer;
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

class DonationFundsNotifierTest extends TestCase
{
    use ProphecyTrait;

    public function testItSendsAnEmailAboutNewDonationFunds(): void
    {
        //arrange
        $donorAccount = new DonorAccount(
            EmailAddress::of('foo@example.com'),
            DonorName::of('Fred', 'Brooks'),
            StripeCustomerId::of('cus_1234'), // this one doesn't matter for the test.
        );

        $transferAmount = Money::fromPence(52_35, Currency::GBP);
        $newBalance = Money::fromPence(17_000_00, Currency::GBP);

        $mailerProphecy = $this->prophesize(Mailer::class);
        $sut = new DonationFundsNotifier($mailerProphecy->reveal());

        // assert
        $mailerProphecy->sendEmail([
            'templateKey' => 'donor-funds-thanks',
            'recipientEmailAddress' => 'foo@example.com',
            'params' => [
                'donorFirstName' => 'Fred',
                'transferAmount' => "£52.35"
            ],
        ])->shouldBeCalledOnce();

        //act
        $sut->notifyRecieptOfAccountFunds($donorAccount, $transferAmount, $newBalance);
    }
}
