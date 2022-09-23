<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-109 – Add Donation.pspCustomerId.
 */
final class Version20220917172013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.pspCustomerId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD pspCustomerId VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP pspCustomerId');
    }
}
