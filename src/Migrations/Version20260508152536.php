<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508152536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field Donation.ryftClientSessionId - seems to be needed to allow taking the payment from server side';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD ryftClientSessionId VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP ryftClientSessionId');
    }
}
