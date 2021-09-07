<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix default fee cover value – assuming this is needed based on tip equivalent from 2019.
 *
 * @see Version20191126051351
 */
final class Version20210907110200 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Replace likely-buggy long term default fee cover value';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE feeCoverAmount feeCoverAmount NUMERIC(18, 2) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation CHANGE feeCoverAmount feeCoverAmount NUMERIC(18, 2) DEFAULT \'0.00\' NOT NULL');
    }
}
