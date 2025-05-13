<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop Charity.donateLinkId field, which has always mirrored Salesforce Account ID for the last ~3 years.
 */
final class Version20230913111156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Charity.donateLinkId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP donateLinkId');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD donateLinkId VARCHAR(255) NOT NULL');
    }
}
