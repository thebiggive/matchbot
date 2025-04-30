<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250429162304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove numbers from names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Donation set 
                Donation.donorFirstName = REGEXP_REPLACE(donorFirstName, '[0-9]{6}', ''),
                Donation.donorLastName = REGEXP_REPLACE(donorLastName, '[0-9]{6}', ''),
                salesforcePushStatus = 'pending-update'
            WHERE 
                donorFirstName rlike '[0-9]{6}' OR
                donorLastName rlike '[0-9]{6}'
            LIMIT 300;
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE DonorAccount set
                DonorAccount.donorName_first = REGEXP_REPLACE(donorName_first, '[0-9]{6}', '')
            WHERE 
                DonorAccount.donorName_first rlike '[0-9]{6}'
            LIMIT 20
        SQL);
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
