<?php

use Doctrine\DBAL\Connection;
use MatchBot\Application\Commands\MarkOldPasswordedAccountsInStripe;
use MatchBot\IntegrationTests\IntegrationTest;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Stripe\Service\CustomerService;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * This test needs access to the identity DB so can only be run in local dev environment - oustide docker.
 * Run like so:
 *
 *
 *
      ```
      export IDENTITY_MYSQL_USER=root
      export IDENTITY_MYSQL_PASSWORD=tbgLocal123
      export IDENTITY_MYSQL_HOST=127.0.0.1
      export IDENTITY_MYSQL_PORT=30051

      vendor/bin/phpunit --config=phpunit-integration.xml integrationTests/MarkOldPasswordedAccountsInStripeTest.php
      ```
 *
 */
class MarkOldPasswordedAccountsInStripeTest extends IntegrationTest
{
    use ProphecyTrait;

    private CommandTester $tester;

    /**
     * @var ObjectProphecy<CustomerService>
     */
    private ObjectProphecy $stripeCustomersProphecy;

    /**
     * @var ObjectProphecy<Redis>
     */
    private ObjectProphecy $redisProphecy;

    public function setUp(): void
    {
        if (getenv('IDENTITY_MYSQL_USER') === false) {
            $this->markTestSkipped("Please set environment variables as shown in docblock to run this test");
        }
        parent::setUp();

        $identityDBConnection = $this->getServiceByName(MarkOldPasswordedAccountsInStripe::IDENTITY_DBAL_CONNECTION_SERVICE_NAME);

        \assert($identityDBConnection instanceof Connection);

        $stripeClientProphecy = $this->prophesize(\Stripe\StripeClient::class);

        $this->stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeClientProphecy->customers = $this->stripeCustomersProphecy->reveal();

        $this->redisProphecy = $this->prophesize(Redis::class);

        $sut = new MarkOldPasswordedAccountsInStripe(
            $this->getService(\Psr\Log\LoggerInterface::class),
            $stripeClientProphecy->reveal(),
            $this->redisProphecy->reveal(),
            $identityDBConnection
        );

        $lockFactoryProphecy = $this->prophesize(\Symfony\Component\Lock\LockFactory::class);
        $lockFactoryProphecy->createLock('matchbot:mark-old-passworded-accounts-in-stripe', \Prophecy\Argument::cetera())
            ->willReturn(new \Symfony\Component\Lock\NoLock());

        $sut->setLockFactory($lockFactoryProphecy->reveal());

        $this->tester = new CommandTester($sut);
    }

    public function testItSendsPasswordMetadataToStripe(): void
    {
        $this->redisProphecy->get('password-push-to-stripe-completed-up-to')
            ->willReturn(false);

        $this->stripeCustomersProphecy->update('cus_NHDQHoQP8Abgbp', ['metadata' => ['hasPasswordSince' => '2023-02-01 12:01:26']])
            ->shouldBeCalled();
        $this->stripeCustomersProphecy->update('cus_NHZfwfpyGaCGLk', ['metadata' => ['hasPasswordSince' => '2023-02-22 11:26:46']])
            ->shouldBeCalled();

        $this->redisProphecy->set('password-push-to-stripe-completed-up-to', '2023-02-22 11:26:46')
            ->shouldBeCalled();

        $status = $this->tester->execute([]);

        $this->assertSame(0, $status);
        $this->assertSame(
            <<<'EOF'
            matchbot:mark-old-passworded-accounts-in-stripe starting!
            matchbot:mark-old-passworded-accounts-in-stripe complete!
            
            EOF,
            $this->tester->getDisplay()
        );
    }

    public function testItContinuesWhereItLeftOffOnSecondRun(): void
    {
        $this->redisProphecy->get('password-push-to-stripe-completed-up-to')
            ->willReturn('2023-02-22 11:26:46');

        $this->stripeCustomersProphecy->update('cus_NP4fRXVvhy9gLU', ['metadata' => ['hasPasswordSince' => '2023-02-22 11:31:21']])
            ->shouldBeCalled();
        $this->stripeCustomersProphecy->update('cus_NRkbj60fdOoytj', ['metadata' => ['hasPasswordSince' => '2023-03-01 15:00:09']])
            ->shouldBeCalled();

        $this->redisProphecy->set('password-push-to-stripe-completed-up-to', '2023-03-01 15:00:09')
            ->shouldBeCalled();

        $status = $this->tester->execute([]);

        $this->assertSame(0, $status);
        $this->assertSame(
            <<<'EOF'
            matchbot:mark-old-passworded-accounts-in-stripe starting!
            matchbot:mark-old-passworded-accounts-in-stripe complete!
            
            EOF,
            $this->tester->getDisplay()
        );
    }
}
