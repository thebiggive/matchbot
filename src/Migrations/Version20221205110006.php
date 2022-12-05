<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-252 – Add indexes to support faster queries for retrospective matching
 */
final class Version20221205110006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes to support faster queries for retrospective matching';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX end_date_and_is_matched ON Campaign (endDate, isMatched)');
        $this->addSql('CREATE INDEX campaign_and_status ON Donation (campaign_id, donationStatus)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX end_date_and_is_matched ON Campaign');
        $this->addSql('DROP INDEX campaign_and_status ON Donation');
    }
}
