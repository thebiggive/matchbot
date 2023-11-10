<?php

namespace MatchBot\Application\Matching;

// something wrong with autoloading in test dirs. Mostly we rely on PHPUnit to do loading for us so it hasn't been an
// issue up to now. Not sure exactly what's wrong with the config in composer.json
require_once(__DIR__ . '/ArrayMatchingStorage.php');

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\RealTimeMatchingStorage;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Tests\Application\Matching\ArrayMatchingStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OptimisticRedisAdapterTest extends TestCase
{
    private RealTimeMatchingStorage $storage;
    private OptimisticRedisAdapter $sut;

    public function setUp(): void
    {
        $this->storage = new ArrayMatchingStorage();

        $this->sut = new OptimisticRedisAdapter($this->storage, $this->createStub(EntityManagerInterface::class), new NullLogger());
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
        $this->sut->runTransactionally(function () {
            $funding = new CampaignFunding();
            $funding->setAmountAvailable('50');
            $this->sut->addAmount($funding, '12.53');

            // set the amount available in the funding to something different so we know the
            // amount returned in the getAmountAvailable call has to be from the realtime storage.
            $funding->setAmountAvailable('3');

            $this->assertSame('50.00', $this->sut->getAmountAvailable($funding));
        });
    }

    public function testItSubtractsAmountForFunding(): void
    {
        $this->sut->runTransactionally(function () {
            $funding = new CampaignFunding();
            $funding->setAmountAvailable('50');
            $amountToSubtract = "10.10";

            $fundBalanceReturned = $this->sut->subtractAmount($funding, $amountToSubtract);

            \assert(50 - 10.10 === 39.9);
            $this->assertSame('39.90', $this->sut->getAmountAvailable($funding));
            $this->assertSame('39.90', $fundBalanceReturned);
        });
    }

    public function testItReleasesFundsInCaseOfRaceCondition(): void
    {
        $this->sut->runTransactionally(function () {
            $this->sut->runTransactionally(function () {
                $funding = new CampaignFunding();
                $funding->setAmountAvailable('50');
                $amountToSubtract = "30";

                $this->sut->subtractAmount($funding, $amountToSubtract);
                try {
                    $this->sut->subtractAmount($funding, $amountToSubtract); // this second subtraction will take the fund negative in redis temporarily, but our Adapter will add back the 30 just subtracted.
                    $this->fail("should have thrown exception on attempt to allocate more than available");
                } catch (TerminalLockException $exception){
                    $this->assertStringContainsString("Releasing final -2000 'cents'", $exception->getMessage());
                }

                $this->assertSame('-10.00', $funding->getAmountAvailable());

                \assert(50 - 30 === 20);
                // this seems like it could be a bug as the amount in storage is negative after the exception was caught?
                $this->assertSame('-10.00', $this->sut->getAmountAvailable($funding));
            });
        });
    }
}
