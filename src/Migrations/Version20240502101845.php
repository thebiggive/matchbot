<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\RealTimeMatchingStorage;
use MatchBot\Domain\CampaignRepository;
use Psr\Container\ContainerInterface;

final class Version20240502101845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match fund corrections for MAT-361 campaigns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            -- reduce amount from 5_000 to 2_500, reduce amountAvailable from 1_890 to 0
            UPDATE CampaignFunding SET amount = amount - 2500, amountAvailable = amountAvailable - 1890 WHERE id = 29101 LIMIT 1;

            -- reduce amount from 2_500 to 1890;
            UPDATE FundingWithdrawal set amount = amount - 610 where id = 717813;
            
            -- reduce amount from 20_000 to 10_000, reduce amountAvailable from 9952 to 0
            UPDATE CampaignFunding SET amount = amount - 10000, amountAvailable = amountAvailable - 9952 WHERE id = 29116 LIMIT 1;

            -- reduce amount from 10_000 to zero
            UPDATE FundingWithdrawal set amount = amount - 9952 where id = 709467 LIMIT 1;
            SQL);

        // commented the following out because I don't think we need it, given that
        // we only use fresh data in redis - it has a one day shelf life. This data is a lot
        // older than that so I think should be fine to ignore. Left in just until code review
        // in case I'm wrong.

        //
        //        /** @var ContainerInterface $container */
        //        $container = require __DIR__.'/../../bootstrap.php';
        //
        //        /** @var \MatchBot\Application\RealTimeMatchingStorage $storage */
        //        $storage = $container->get(RealTimeMatchingStorage::class);
        //
        //        $storage->decrBy('fund-29101-available-opt', 1_890);
        //        $storage->decrBy('fund-29116-available-opt', 9952);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE CampaignFunding SET amount = amount + 2500, amountAvailable = amountAvailable - 1890 WHERE id = 29101 LIMIT 1;

            UPDATE FundingWithdrawal set amount = amount + 610 where id = 717813;
            
            UPDATE CampaignFunding SET amount = amount + 10000, amountAvailable = amountAvailable - 9952 WHERE id = 29116 LIMIT 1;

            UPDATE FundingWithdrawal set amount = amount + 9952 where id = 709467 LIMIT 1;
            SQL);
    }
}
