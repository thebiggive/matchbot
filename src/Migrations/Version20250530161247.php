<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250530161247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add campaign.hidden column';
    }

    public function up(Schema $schema): void
    {
        /* we could extract the data from the JSON column, but I ran the query below
         * in prod and it just returned null and false. We very rarely hide campaigns, mostly just when there are
         * technical problems that stop us accepting donations to them.
         *
         * SELECT distinct JSON_VALUE(salesforceData, '$.hidden') from Campaign;
         */

        $this->addSql('ALTER TABLE Campaign ADD hidden TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP hidden');
    }
}
