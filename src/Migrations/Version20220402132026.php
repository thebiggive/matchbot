<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-239 – add fields to store more Gift Aid claim outcome metadata.
 */
final class Version20220402132026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fields to store more Gift Aid claim outcome metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD tbgGiftAidRequestConfirmedCompleteAt DATETIME DEFAULT NULL, ADD tbgGiftAidRequestCorrelationId VARCHAR(255) DEFAULT NULL, ADD tbgGiftAidResponseDetail TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP tbgGiftAidRequestConfirmedCompleteAt, DROP tbgGiftAidRequestCorrelationId, DROP tbgGiftAidResponseDetail');
    }
}
