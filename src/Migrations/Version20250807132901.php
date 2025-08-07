<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250807132901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make donation.expectedMatchAmount non-null as required for an embeddable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE expectedMatchAmount_amountInPence expectedMatchAmount_amountInPence INT NOT NULL, CHANGE expectedMatchAmount_currency expectedMatchAmount_currency VARCHAR(3) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE expectedMatchAmount_amountInPence expectedMatchAmount_amountInPence INT DEFAULT 0 NOT NULL, CHANGE expectedMatchAmount_currency expectedMatchAmount_currency VARCHAR(3) DEFAULT \'GBP\' NOT NULL');
    }
}
