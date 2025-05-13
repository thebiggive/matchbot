<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240902163827 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove redundancy in new DonorAccount property names';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE DonorAccount
                ADD homeAddressLine1 VARCHAR(255) DEFAULT NULL,
                ADD homePostcode VARCHAR(255) DEFAULT NULL,
                ADD billingPostcode VARCHAR(255) DEFAULT NULL,
                
                DROP donorHomeAddressLine1,
                DROP donorHomePostcode,
                DROP donorBillingPostcode
            SQL
            );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE DonorAccount
                ADD donorHomeAddressLine1 VARCHAR(255) DEFAULT NULL,
                ADD donorHomePostcode VARCHAR(255) DEFAULT NULL,
                ADD donorBillingPostcode VARCHAR(255) DEFAULT NULL,
                
                DROP homeAddressLine1,
                DROP homePostcode,
                DROP billingPostcode
            SQL
            );
    }
}
