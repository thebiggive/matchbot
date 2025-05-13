<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240903101531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UUID column to donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD uuid CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6FA7403D17F50A6 ON DonorAccount (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_6FA7403D17F50A6 ON DonorAccount');
        $this->addSql('ALTER TABLE DonorAccount DROP uuid');
    }
}
