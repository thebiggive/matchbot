<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251215161505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE FundingWithdrawal ADD reversedBy_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE FundingWithdrawal ADD CONSTRAINT FK_5C8EAC125F1168E2 FOREIGN KEY (reversedBy_id) REFERENCES FundingWithdrawal (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C8EAC125F1168E2 ON FundingWithdrawal (reversedBy_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE FundingWithdrawal DROP FOREIGN KEY FK_5C8EAC125F1168E2');
        $this->addSql('DROP INDEX UNIQ_5C8EAC125F1168E2 ON FundingWithdrawal');
        $this->addSql('ALTER TABLE FundingWithdrawal DROP reversedBy_id');
    }
}
