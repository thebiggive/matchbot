<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250806170146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.expectedMatchAmount';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD expectedMatchAmount_amountInPence INT NOT NULL DEFAULT 0, ADD expectedMatchAmount_currency VARCHAR(3) NOT NULL DEFAULT \'GBP\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP expectedMatchAmount_amountInPence, DROP expectedMatchAmount_currency');
    }
}
