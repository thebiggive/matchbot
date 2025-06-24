<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250623165155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move target related fields from wrong entity MetaCampaign to Campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD total_funding_allocation_amountInPence INT NOT NULL, ADD total_funding_allocation_currency VARCHAR(3) NOT NULL, ADD amount_pledged_amountInPence INT NOT NULL, ADD amount_pledged_currency VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE MetaCampaign DROP total_funding_allocation_amountInPence, DROP total_funding_allocation_currency, DROP amount_pledged_amountInPence, DROP amount_pledged_currency');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP total_funding_allocation_amountInPence, DROP total_funding_allocation_currency, DROP amount_pledged_amountInPence, DROP amount_pledged_currency');
        $this->addSql('ALTER TABLE MetaCampaign ADD amount_pledged_amountInPence INT NOT NULL, ADD amount_pledged_currency VARCHAR(3) NOT NULL, ADD total_matched_funds_available_amountInPence INT NOT NULL, ADD total_matched_funds_available_currency VARCHAR(3) NOT NULL');
    }
}
