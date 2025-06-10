<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250610092545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reduce length of currency column for money embeddable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE total_adjustment_currency total_adjustment_currency VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE donationAmount_currency donationAmount_currency VARCHAR(3) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign CHANGE total_adjustment_currency total_adjustment_currency VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE donationAmount_currency donationAmount_currency VARCHAR(255) NOT NULL');
    }
}
