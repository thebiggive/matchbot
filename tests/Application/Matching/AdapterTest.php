<?php

namespace MatchBot\Tests\Application\Matching;

use MatchBot\Application\Matching\LessThanRequestedAllocatedException;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\TerminalLockException;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AdapterTest extends TestCase
{
    private ArrayMatchingStorage $storage;
    private Adapter $sut;

    #[\Override]
    public function setUp(): void
    {
        $this->storage = new ArrayMatchingStorage();

        $this->sut = new Adapter(
            $this->storage,
            new NullLogger(),
        );
    }

    public function testItReturnsAmountAvailableFromAFundingNotInStorage(): void
    {
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '10000',
            amountAvailable: '12.53',
        );
        $funding->setId(1);

        $amountAvaialble = $this->sut->getAmountAvailable($funding);

        $this->assertSame('12.53', $amountAvaialble);
    }

    public function testItAddsAmountForFunding(): void
    {
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '50',
        );
        $funding->setId(1);
        $this->sut->addAmount($funding, '12.53');

        // set the amount available in the funding to something different so we know the
        // amount returned in the getAmountAvailable call has to be from the realtime storage.
        $funding->setAmountAvailable('3');

        \assert(50.0 + 12.53 === 62.53);
        $this->assertSame('62.53', $this->sut->getAmountAvailable($funding));
    }

    public function testItSubtractsAmountForFunding(): void
    {
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '50',
        );
        $funding->setId(1);
        $amountToSubtract = "10.10";

        $fundBalanceReturned = $this->sut->subtractAmount($funding, $amountToSubtract);

        \assert(50.0 - 10.10 === 39.9);
        $this->assertSame('39.90', $this->sut->getAmountAvailable($funding));
        $this->assertSame('39.90', $fundBalanceReturned);
    }

    public function testItReleasesFundsInCaseOfRaceCondition(): void
    {
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '50',
        );
        $funding->setId(1);
        $amountToSubtract = "30";

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
    }


    public function testItBailsOutAndReleasesFundsIfRetryingDoesntWorkDueToConcurrentRequests(): void
    {
        // let's assume another thread is causing the funds to reduce by 40 pounds just
        // after each time we increase it by 30 pounds – via `subtractAmountWithoutSavingToDB()`
        // recovery calling `incrBy()` with overspent amount.
        $this->storage->setPreIncrCallBack(function (string $key) {
            return $this->storage->decrBy($key, 40_00);
        });

        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '50',
        );
        $funding->setId(53);
        $funding->setAmountAvailable('50');
        $amountToSubtract = "30";

        $this->sut->subtractAmount($funding, $amountToSubtract);

        try {
            $this->sut->subtractAmount($funding, $amountToSubtract);
            $this->fail("should have thrown exception on attempt to allocate more than available");
        } catch (TerminalLockException $exception) {
            // this -140_00 number is not very important - we already released part of the funds earlier,
            // so this is just whatever happened to be left to release at the end. It's influenced by what happened
            // in the callback (simulated other process) because that influences how much we tried to release earlier,
            // and so this has to balance it out. If we released more earlier in the attempt to get the fund to zero
            // we would release less here and vice versa.
            //
            $this->assertSame(
                "Fund 53 balance sub-zero after 6 attempts. Releasing final -14000 'cents'",
                $exception->getMessage()
            );
        }

        // fund started with 50, we successfully removed 30, and the callback removed 40 six times. We cleared up after
        // our unsuccessful attempts to withdraw more, not cleared up after the callback as would be the responsibility
        // of the other process.
        \assert(50 - 30 - 40 * 6 === -220);
        $this->assertSame('-220.00', $this->sut->getAmountAvailable($funding));
    }

    public function testItDeletesCampaignFundingData(): void
    {
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '1',
        );
        $funding->setId(1);
        $this->sut->addAmount($funding, '5');

        $funding->setAmountAvailable('53');
        $this->sut->delete($funding);

        // if we hadn't deleted then this would return the 6 from the real time storage.
        $this->assertSame('53', $this->sut->getAmountAvailable($funding));
    }

    public function testItReleasesNewlyAllocatedFunds(): void
    {
        // arrange
        $funding = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000',
            amountAvailable: '50',
        );
        $funding->setId(1);
        $amountToSubtract = "10.10";

        $fundBalanceReturned = $this->sut->subtractAmount($funding, $amountToSubtract);

        // act
        $this->sut->releaseNewlyAllocatedFunds();

        // assert
        $this->assertSame('50.00', $this->sut->getAmountAvailable($funding));
        $this->assertSame('39.90', $fundBalanceReturned);
    }
}
