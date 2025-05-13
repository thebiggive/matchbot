<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250311182346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add Charity.address_* columns';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE Charity 
                ADD address_line1 VARCHAR(255) DEFAULT NULL, 
                ADD address_line2 VARCHAR(255) DEFAULT NULL, 
                ADD address_city VARCHAR(255) DEFAULT NULL, 
                ADD address_postalCode VARCHAR(255) DEFAULT NULL, 
                ADD address_country VARCHAR(255) DEFAULT NULL
            SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE Charity 
                DROP address_line1, 
                DROP address_line2, 
                DROP address_city, 
                DROP address_postalCode, 
                DROP address_country
            SQL
        );
    }
}
