<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240821103038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Donation.feeCover and Campaign.feePercentage, as these have always been zero and null in prod';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP feePercentage');
        $this->addSql('ALTER TABLE Donation DROP feeCoverAmount');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD feePercentage NUMERIC(3, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation ADD feeCoverAmount NUMERIC(18, 2) NOT NULL');
    }
}
