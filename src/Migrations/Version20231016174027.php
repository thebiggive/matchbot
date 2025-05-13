<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231016174027 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add DonorName to donation account records';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD donorName_first VARCHAR(255) NOT NULL, ADD donorName_last VARCHAR(255) NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount DROP donorName_first, DROP donorName_last');
    }
}
