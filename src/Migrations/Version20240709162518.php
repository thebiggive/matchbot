<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240709162518 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'ADD Charity.updateFromSFRequiredSince column';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD updateFromSFRequiredSince DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_4CC08E82D8961D21 ON Charity (salesforceId)');
        $this->addSql('CREATE INDEX IDX_4CC08E823A4E7063 ON Charity (updateFromSFRequiredSince)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_4CC08E82D8961D21 ON Charity');
        $this->addSql('DROP INDEX IDX_4CC08E823A4E7063 ON Charity');
        $this->addSql('ALTER TABLE Charity DROP updateFromSFRequiredSince');
    }
}
