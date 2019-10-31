<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename `order` to avoid DQL keyword clashes
 */
final class Version20191031132400 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Rename `order` to avoid DQL keyword clashes';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE CampaignFunding CHANGE `order` allocationOrder INT NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE CampaignFunding CHANGE allocationorder `order` INT NOT NULL');
    }
}
