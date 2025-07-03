<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250703103053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fields and indexes to campaign as required for counting campaigns in a metacampaign. May also be required for search etc.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD relatedApplicationStatus VARCHAR(64) DEFAULT NULL, ADD relatedApplicationCharityResponseToOffer VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX relatedApplicationStatus ON Campaign (relatedApplicationStatus)');
        $this->addSql('CREATE INDEX relatedApplicationCharityResponseToOffer ON Campaign (relatedApplicationCharityResponseToOffer)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX relatedApplicationStatus ON Campaign');
        $this->addSql('DROP INDEX relatedApplicationCharityResponseToOffer ON Campaign');
        $this->addSql('ALTER TABLE Campaign DROP relatedApplicationStatus, DROP relatedApplicationCharityResponseToOffer');
    }
}
