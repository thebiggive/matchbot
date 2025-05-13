<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240712140753 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Set all Charity.updateFromSFRequiredSince null as should have been to start';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE Charity set updateFromSFRequiredSince = NULL WHERE Charity.id > 0');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE Charity set updateFromSFRequiredSince = "0000-00-00 00:00:00" WHERE Charity.id > 0');
    }
}
