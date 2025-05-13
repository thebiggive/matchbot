<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-25 – Add fields to store UK regulator info.
 */
final class Version20220419180844 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add fields to store UK regulator info';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD regulator VARCHAR(4) DEFAULT NULL, ADD regulatorNumber VARCHAR(10) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP regulator, DROP regulatorNumber');
    }
}
