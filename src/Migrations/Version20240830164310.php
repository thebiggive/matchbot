<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240830164310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add columns and indexes for regular giving, delete unused columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD mandate_id INT UNSIGNED DEFAULT NULL, ADD mandateSequenceNumber INT DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation ADD CONSTRAINT FK_C893E3F66C1129CD FOREIGN KEY (mandate_id) REFERENCES RegularGivingMandate (id)');
        $this->addSql('CREATE INDEX IDX_C893E3F66C1129CD ON Donation (mandate_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C893E3F621F7BD156C1129CD ON Donation (mandateSequenceNumber, mandate_id)');
        $this->addSql('ALTER TABLE RegularGivingMandate ADD donationsCreatedUpTo DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX donationsCreatedUpTo ON RegularGivingMandate (donationsCreatedUpTo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP FOREIGN KEY FK_C893E3F66C1129CD');
        $this->addSql('DROP INDEX IDX_C893E3F66C1129CD ON Donation');
        $this->addSql('DROP INDEX UNIQ_C893E3F621F7BD156C1129CD ON Donation');
        $this->addSql('ALTER TABLE Donation DROP mandate_id, DROP mandateSequenceNumber');
        $this->addSql('DROP INDEX donationsCreatedUpTo ON RegularGivingMandate');
        $this->addSql('ALTER TABLE RegularGivingMandate DROP donationsCreatedUpTo');
    }
}
