<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240521122101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove GA setting from inelligble donations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation set giftAid = 0, tipGiftAid = 0, salesforcePushStatus = 'pending-update'
            WHERE Donation.salesforceId IN ('a0669000020MEiaAAG', 'a0669000020M9saAAC', 'a0669000020LtFrAAK')
            LIMIT 3;
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back");
    }
}
