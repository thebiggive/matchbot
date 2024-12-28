<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use DI\Container;
use MatchBot\Application\Messenger\Handler\PersonHandler;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Messages\Person;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

class PersonHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonorAccountRepository>  */
    private ObjectProphecy $donorAccountRepositoryProphecy;

    public function setUp(): void
    {
        $this->donorAccountRepositoryProphecy = $this->prophesize(DonorAccountRepository::class);
    }

    public function testItPersistsOnePerson(): void
    {
        $message = self::somePersonMessage();

        $this->donorAccountRepositoryProphecy->findByStripeIdOrNull(Argument::type(StripeCustomerId::class))
            ->shouldBeCalledOnce()
            ->willReturn(null);
        $this->donorAccountRepositoryProphecy
            ->save(Argument::type(DonorAccount::class))
            ->shouldBeCalledOnce();

        $container = new Container();
        $container->set(DonorAccountRepository::class, $this->donorAccountRepositoryProphecy->reveal());

        $sut = new PersonHandler($container, new NullLogger());

        $sut->__invoke($message);
    }

    private static function somePersonMessage(): Person
    {
        $message = new Person();
        $message->id = Uuid::uuid4();
        $message->first_name = 'Jamie';
        $message->last_name = 'Loftus';
        $message->email_address = 'j@example.org';
        $message->stripe_customer_id = 'cus_123';

        return $message;
    }
}
