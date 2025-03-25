<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-410 – Prepare schema for new allocation order method
 */
final class Version20250325123754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prepare schema for new allocation order method';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX available_fundings ON CampaignFunding');
        $this->addSql('CREATE INDEX available_fundings ON CampaignFunding (amountAvailable, id)');
        $this->addSql('ALTER TABLE Fund ADD allocationOrder INT NOT NULL');
        $this->addSql('CREATE INDEX allocationOrder ON Fund (allocationOrder)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX available_fundings ON CampaignFunding');
        $this->addSql('CREATE INDEX available_fundings ON CampaignFunding (amountAvailable, allocationOrder, id)');
        $this->addSql('DROP INDEX allocationOrder ON Fund');
        $this->addSql('ALTER TABLE Fund DROP allocationOrder');
    }
}
