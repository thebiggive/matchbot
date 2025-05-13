<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240902154849 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add columns to DonorAccount table to support regular giving';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE DonorAccount 
                    ADD billingCountryCode VARCHAR(2) DEFAULT NULL,
                    ADD donorHomeAddressLine1 VARCHAR(255) DEFAULT NULL,
                    ADD donorHomePostcode VARCHAR(255) DEFAULT NULL,
                    ADD donorBillingPostcode VARCHAR(255) DEFAULT NULL
                SQL
);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE DonorAccount
                    DROP billingCountryCode,
                    DROP donorHomeAddressLine1,
                    DROP donorHomePostcode,
                    DROP donorBillingPostcode
                SQL
);
    }
}
