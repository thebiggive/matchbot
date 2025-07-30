<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250729165929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment card details (brand & country) to donation records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD paymentCard_brand VARCHAR(255) DEFAULT NULL, ADD paymentCard_country VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP paymentCard_brand, DROP paymentCard_country');
    }
}
