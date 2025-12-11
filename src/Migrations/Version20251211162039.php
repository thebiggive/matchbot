<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20251211162039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move funding withdrawal manually added in error to wrong campaign funding';
    }

    public function up(Schema $schema): void
    {
         $this->addSql(<<<SQL
            UPDATE FundingWithdrawal SET campaignFunding_id = 48743 where id = 1214887 AND donation_id = 1361196 LIMIT 1;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE FundingWithdrawal SET campaignFunding_id = 40616 where id = 1214887 and donation_id = 1361196 LIMIT 1;
        SQL);
    }
}
