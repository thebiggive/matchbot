<?php

namespace MatchBot\Tests\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\LessThanRequestedAllocatedException;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\TerminalLockException;
use MatchBot\Domain\CampaignFunding;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;

class AdapterTest extends TestCase
{
    use ProphecyTrait;

    private ArrayMatchingStorage $storage;
    private Adapter $sut;

    /**
     * @var ObjectProphecy<EntityManagerInterface>
     */
    private ObjectProphecy $entityManagerProphecy;

    public function setUp(): void
    {
        $this->storage = new ArrayMatchingStorage();

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);
        $this->entityManagerProphecy->transactional(Argument::type(\Closure::class))->will(function (array $args) {
            $closure = $args[0];
            \assert($closure instanceof \Closure);
            $closure();
        });

        $this->sut = new Adapter(
            $this->storage,
            $this->entityManagerProphecy->reveal(),
            new NullLogger(),
        );
    }

    public function testItReturnsAmountAvailableFromAFundingNotInStorage(): void
    {
        $funding = new CampaignFunding();
        $funding->setAmountAvailable('12.53');

        $amountAvaialble = $this->sut->getAmountAvailable($funding);

        $this->assertSame('12.53', $amountAvaialble);
    }

    public function testItAddsAmountForFunding(): void
    {
        $funding = new CampaignFunding();
        $funding->setAmountAvailable('50');
        $this->sut->addAmountTransactionally($funding, '12.53');
        $this->entityManagerProphecy->persist($funding)->shouldBeCalled();

        // set the amount available in the funding to something different so we know the
        // amount returned in the getAmountAvailable call has to be from the realtime storage.
        $funding->setAmountAvailable('3');

        \assert(50 + 12.53 === 62.53);
        $this->assertSame('62.53', $this->sut->getAmountAvailable($funding));
    }

    public function testItSubtractsAmountForFunding(): void
    {
        $this->sut->runTransactionally(function () {
            $funding = new CampaignFunding();
            $funding->setAmountAvailable('50');
            $amountToSubtract = "10.10";

            $this->entityManagerProphecy->persist($funding)->shouldBeCalled();
            $fundBalanceReturned = $this->sut->subtractAmount($funding, $amountToSubtract);

            \assert(50 - 10.10 === 39.9);
            $this->assertSame('39.90', $this->sut->getAmountAvailable($funding));
            $this->assertSame('39.90', $fundBalanceReturned);
        });
    }

    public function testItReleasesFundsInCaseOfRaceCondition(): void
    {
        $this->sut->runTransactionally(function () {
                $funding = new CampaignFunding();
                $funding->setAmountAvailable('50');
                $amountToSubtract = "30";

            $this->entityManagerProphecy->persist($funding)->shouldBeCalled();
            $this->sut->subtractAmount($funding, $amountToSubtract);
            try {
                // this second subtraction will take the fund negative in redis temporarily, but our Adapter will add
                // back the 30 just subtracted.
                $this->sut->subtractAmount($funding, $amountToSubtract);
                $this->fail("should have thrown exception on attempt to allocate more than available");
            } catch (LessThanRequestedAllocatedException $exception) {
                $this->assertStringContainsString("Less than requested was allocated", $exception->getMessage());
            }

                $this->assertSame('0.00', $funding->getAmountAvailable());
                $this->assertSame('0.00', $this->sut->getAmountAvailable($funding));
        });
    }


    public function testItBailsOutAndReleasesFundsIfRetryingDoesntWorkDueToConcurrentRequests(): void
    {
        // let's assume another thread is causing the funds to reduce by 30 pounds just
        // after each time we increase it by 30 pounds.
        $this->storage->setPreIncrCallBack(function (string $key) {
            return $this->storage->decrBy($key, 30_00);
        });

        $this->sut->runTransactionally(function () {
            $funding = new CampaignFunding();
            $funding->setId(53);
            $funding->setAmountAvailable('50');
            $amountToSubtract = "30";

            $this->sut->subtractAmount($funding, $amountToSubtract);

            $this->expectException(TerminalLockException::class);
            // todo - work out where the -100_00 figure here comes from. Message below is just pasted in from
            // see ticket MAT-332
            // result of running the test.
            $this->expectExceptionMessage("Fund 53 balance sub-zero after 6 attempts. Releasing final -10000 'cents'");
            $this->sut->subtractAmount($funding, $amountToSubtract);
        });
    }

    public function testItDeletesCampaignFundingData(): void
    {
        $funding = new CampaignFunding();
        $funding->setAmountAvailable('1');
        $this->entityManagerProphecy->persist($funding)->shouldBeCalled();
        $this->sut->addAmountTransactionally($funding, '5');

        $funding->setAmountAvailable('53');
        $this->sut->delete($funding);

        // if we hadn't deleted then this would return the 6 from the real time storage.
        $this->assertSame('53', $this->sut->getAmountAvailable($funding));
    }

    public function testItReleasesNewlyAllocatedFunds(): void
    {
        // arrange
        $funding = new CampaignFunding();
        $funding->setAmountAvailable('50');
        $amountToSubtract = "10.10";

        $this->entityManagerProphecy->persist($funding)->shouldBeCalled();
        $fundBalanceReturned = $this->sut->runTransactionally(function () use ($funding, $amountToSubtract) {
            return $this->sut->subtractAmount($funding, $amountToSubtract);
        });

        // act
        $this->sut->releaseNewlyAllocatedFunds();

        // assert
        $this->assertSame('50.00', $this->sut->getAmountAvailable($funding));
        $this->assertSame('39.90', $fundBalanceReturned);
    }
}
