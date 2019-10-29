<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Use fundType for multiple-inheritance across ChampionFund and Pledge
 */
final class Version20191029094342 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Use fundType for multiple-inheritance across ChampionFund and Pledge';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Fund CHANGE fundType fundType VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Fund CHANGE fundType fundType VARCHAR(8) NOT NULL COLLATE utf8mb4_unicode_ci');
    }
}
