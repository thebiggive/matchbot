<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Commands\PushDailyFundTotals;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\FundType;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;

class PushDailyFundTotalsTest extends IntegrationTest
{
    public function testRun(): void
    {
        // arrange
        $lockFactory = new LockFactory(new AlwaysAvailableLockStore());
        $output = new BufferedOutput();

        $this->prepareData();

        $application = $this->buildMinimalApp($lockFactory);

        // act
        $command = new PushDailyFundTotals(
            $this->getService(FundRepository::class),
            $this->getService(RoutableMessageBus::class),
        );
        $command->setApplication($application);
        $command->setLockFactory($lockFactory);
        $command->run(new ArrayInput([]), $output);

        // assert
        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:push-daily-fund-totals starting!',
            'Pushed 1 fund totals to Salesforce for open campaigns',
            'matchbot:push-daily-fund-totals complete!',
            '',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }

    private function buildMinimalApp(LockFactory $lockFactory): Application
    {
        $app = new Application();

        $command = $this->getService(PushDailyFundTotals::class);
        $command->setLockFactory($lockFactory);
        $app->add($command);

        return $app;
    }

    private function prepareData(): void
    {
        $this->addFundedCampaignAndCharityToDB(
            campaignSfId: $this->randomString(),
            fundWithAmountInPounds: 5,
            fundType: FundType::ChampionFund,
        );
    }
}
