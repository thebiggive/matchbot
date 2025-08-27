<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove Charity postal address.
 */
final class Version20250827130026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Charity postal address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP address_line1, DROP address_line2, DROP address_city, DROP address_postalCode, DROP address_country');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD address_line1 VARCHAR(255) DEFAULT NULL, ADD address_line2 VARCHAR(255) DEFAULT NULL, ADD address_city VARCHAR(255) DEFAULT NULL, ADD address_postalCode VARCHAR(255) DEFAULT NULL, ADD address_country VARCHAR(255) DEFAULT NULL');
    }
}
