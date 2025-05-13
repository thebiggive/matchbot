<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241217160704 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return '';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE amount_amountInPence donationAmount_amountInPence INT NOT NULL, CHANGE amount_currency donationAmount_currency VARCHAR(255) NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE donationAmount_amountInPence amount_amountInPence INT NOT NULL, CHANGE donationAmount_currency amount_currency VARCHAR(255) NOT NULL');
    }
}
