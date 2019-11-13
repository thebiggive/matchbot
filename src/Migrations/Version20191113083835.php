<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add indexes targeted at improving performance of MatchBot's current queries
 */
final class Version20191113083835 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add indexes to improved key queries\' performance';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE INDEX available_fundings ON CampaignFunding (amountAvailable, allocationOrder, id)');
        $this->addSql('CREATE INDEX date_and_status ON Donation (createdAt, donationStatus)');
        $this->addSql('CREATE INDEX salesforcePushStatus ON Donation (salesforcePushStatus)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX available_fundings ON CampaignFunding');
        $this->addSql('DROP INDEX date_and_status ON Donation');
        $this->addSql('DROP INDEX salesforcePushStatus ON Donation');
    }
}
