<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251127121956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete donor account & anonymise pending donation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM DonorAccount
            WHERE DonorAccount.uuid = '1f0ca134-f3e6-62fe-b0b6-3fdb2dbceffd'
            LIMIT 1
            SQL
        );

        $this->addSql(<<<SQL
            UPDATE Donation SET 
                                donorEmailAddress = 'anonymised@example.com',
                                donorFirstName = 'anonymised',
                                donorHomeAddressLine1 = 'anonymised',
                                donorHomePostcode = null,
                                donorLastName = 'anonymised',
                                donorPostalAddress = null,
                                salesforcePushStatus = 'pending-update',
                                tbgComms = 0
            WHERE Donation.donorUUID = '1f0ca134-f3e6-62fe-b0b6-3fdb2dbceffd'
            AND Donation.donationStatus = 'Pending'
            LIMIT 10; -- there may be fewer than 10 matching this in prod, not publishing the exact number here.                
        SQL
        );
    }
}
