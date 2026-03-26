<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260326154635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PSP column to charity to allow supporting Ryft as alternative to Stripe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD psp VARCHAR(255) DEFAULT \'stripe\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP psp');
    }
}
