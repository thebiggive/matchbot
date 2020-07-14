<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add fields to support multiple PSPs and Stripe PaymentIntent client secrets
 */
final class Version20200713104818 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add fields to support multiple PSPs and Stripe PaymentIntent client secrets';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        // Migration modified to first set up existing donations with
        // psp = 'enthuse'
        $this->addSql("ALTER TABLE Donation ADD psp VARCHAR(20) DEFAULT 'enthuse', ADD clientSecret VARCHAR(255) DEFAULT NULL");

        // Once this change is done, remove the psp default and make psp
        // non-nullable going forward.
        $this->addSql('ALTER TABLE Donation CHANGE psp psp VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE Donation DROP psp, DROP clientSecret');
    }
}
