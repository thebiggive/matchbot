<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240821132637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.preAuthorizationDate';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE Donation ADD preAuthorizationDate DATE DEFAULT NULL COMMENT \'(DC2Type:LocalDate)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE Donation DROP preAuthorizationDate');
    }
}
