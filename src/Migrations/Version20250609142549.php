<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250609142549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add total adjustment columns to metacampaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign ADD total_adjustment_amountInPence INT NOT NULL, ADD total_adjustment_currency VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE MetaCampaign DROP total_adjustmentamountInPence, DROP total_adjustmentcurrency');
    }
}
