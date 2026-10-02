<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Application\Email\EmailMessage;
use MatchBot\Application\Messenger\EmailRequest;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class DonationFundsNotifierTest extends TestCase
{
    use ProphecyTrait;

    public function testItSendsAnEmailAboutNewDonationFunds(): void
    {
        //arrange
        $donorAccount = new DonorAccount(
            self::randomPersonId(),
            EmailAddress::of('foo@example.com'),
            DonorName::of('Fred', 'Brooks'),
            StripeCustomerId::of('cus_1234'), // this one doesn't matter for the test.
            isOrganisation: false,
            organisationName: null,
        );

        $transferAmount = Money::fromPence(52_35, Currency::GBP);
        $newBalance = Money::fromPence(17_000_00, Currency::GBP);

        $busProphecy = $this->prophesize(MessageBusInterface::class);
        $sut = new DonationFundsNotifier($busProphecy->reveal());

        // assert
        $busProphecy->dispatch(Argument::type(Envelope::class))
            ->shouldBeCalledOnce()
            ->will(function (array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                \assert($message instanceof EmailRequest);

                $emailRequest = $message;
                $emailMessage = $emailRequest->emailMessage;

                \assert($emailMessage->templateKey === 'donor-funds-thanks');
                \assert($emailMessage->emailAddress->email === 'foo@example.com');
                \assert($emailMessage->params['donorFirstName'] === 'Fred');
                \assert($emailMessage->params['donorLastName'] === 'Brooks');
                \assert($emailMessage->params['transferAmount'] === '£52.35');

                return $envelope;
            });

        //act
        $sut->notifyRecieptOfAccountFunds($donorAccount, $transferAmount, $newBalance);
    }
}
