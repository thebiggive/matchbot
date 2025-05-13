<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240823165402 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Switch preAuthorizationDate to use DateTimeImmutable';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE preAuthorizationDate preAuthorizationDate DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation CHANGE preAuthorizationDate preAuthorizationDate DATE DEFAULT NULL COMMENT \'(DC2Type:LocalDate)\'');
    }
}
