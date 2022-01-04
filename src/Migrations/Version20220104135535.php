<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-221 – Add field for Stripe Transfer IDs.
 */
final class Version20220104135535 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add field for Stripe Transfer IDs';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation ADD transferId VARCHAR(255) DEFAULT NULL, CHANGE collectedAt collectedAt DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C893E3F6AE9826DA ON Donation (transferId)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX UNIQ_C893E3F6AE9826DA ON Donation');
        $this->addSql('ALTER TABLE Donation DROP transferId, CHANGE collectedAt collectedAt DATETIME NOT NULL');
    }
}
