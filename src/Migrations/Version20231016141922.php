<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231016141922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD email VARCHAR(255) NOT NULL, DROP emailAddress, CHANGE stripeCustomerId stripeCustomerId VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD emailAddress VARCHAR(256) NOT NULL, DROP email, CHANGE stripeCustomerId stripeCustomerId VARCHAR(50) NOT NULL');
    }
}
