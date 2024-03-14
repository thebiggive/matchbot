<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\ReconcilePastPayouts;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Stripe\Event;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class ReconcilePastPayoutsTest extends TestCase
{
    use DonationTestDataTrait;

    private function skipTest(): void
    {
        // hiding the `never` from Psalm by upcasting it to void, otherwise it complains about dead code.
        $this->markTestSkipped('Test too slow to run every time.');
    }

    public function testRun(): void
    {
        $this->skipTest();
        // Just mock out without checks for this temporary command. The output lines check is enough to be fairly
        // sure we're publishing the right messages. Ended up copying the CSV logic to mock it because Envelope is
        // final.
        $bus = $this->prophesize(RoutableMessageBus::class);

        $payouts = str_getcsv(ReconcilePastPayouts::PAYOUT_INFO_CSV, "\n");
        $payouts = array_map('str_getcsv', $payouts);
        foreach ($payouts as $payout) {
            \assert(is_string($payout[0]));
            $payoutId = $payout[1];
            $message = (new StripePayout())
                ->setConnectAccountId($payout[0])
                ->setPayoutId($payoutId);

            $stamps = [
                new BusNameStamp(Event::PAYOUT_PAID),
                new TransportMessageIdStamp("payout.paid.$payoutId"),
            ];

            $envelope = new Envelope($message, $stamps);
            $bus->dispatch($envelope)->shouldBeCalledOnce()->willReturn($envelope);
        }

        $commandTester = new CommandTester($this->getCommand($bus));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:reconcile-payouts starting!',
            'Processing 173 payouts...',
            'Completed past payout processing',
            'matchbot:reconcile-payouts complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    /**
     * @param ObjectProphecy<RoutableMessageBus> $routableBusProphecy
     */
    private function getCommand(ObjectProphecy $routableBusProphecy): ReconcilePastPayouts
    {
        $logger = new NullLogger();
        $command = new ReconcilePastPayouts($routableBusProphecy->reveal(), $logger);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger($logger); // private service in superclass.

        return $command;
    }
}
