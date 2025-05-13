<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240517110631 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove PII from pending donations relating to deleted accounts';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation SET 
                                donorEmailAddress = 'anonymised@example.com',
                                donorFirstName = 'anonymised',
                                donorHomeAddressLine1 = 'anonymised',
                                donorHomePostcode = null,
                                donorLastName = 'anonymised',
                                donorPostalAddress = null,
                                salesforcePushStatus = 'pending-update'
            WHERE Donation.donationStatus = 'Pending' 
            AND Donation.pspCustomerId IN ('cus_PyLyjSXfCXTsTr', 'cus_PyZ4LHTGMHq7Qz')
            LIMIT 10; -- there may be fewer than 10 matching this in prod, not publishing the exact number here.                
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("Irreversible migration");
    }
}
