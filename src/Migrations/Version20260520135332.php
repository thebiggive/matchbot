<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520135332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Donation.ryftPaymentSessionId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD ryftPaymentSessionId VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP ryftPaymentSessionId');
    }
}
