<?php

namespace MatchBot\Application\Matching;

// something wrong with autoloading in test dirs. Mostly we rely on PHPUnit to do loading for us so it hasn't been an
// issue up to now. Not sure exactly what's wrong with the config in composer.json
require_once (__DIR__ . '/ArrayMatchingStorage.php');

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
}
