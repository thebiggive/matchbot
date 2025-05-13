<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2722 – remove Salesforce update tracking field that's not being used
 */
final class Version20240823121343 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove Salesforce update tracking field that\'s not being used';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_4CC08E823A4E7063 ON Charity');
        // Commenting out DROP statements below that have run in staging, before they run in prod.
      //  $this->addSql('ALTER TABLE Charity DROP updateFromSFRequiredSince');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD updateFromSFRequiredSince DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_4CC08E823A4E7063 ON Charity (updateFromSFRequiredSince)');
    }
}
