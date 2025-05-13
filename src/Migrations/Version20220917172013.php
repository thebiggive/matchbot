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
    #[\Override]
    public function getDescription(): string
    {
        return 'Add Donation.pspCustomerId';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD pspCustomerId VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP pspCustomerId');
    }
}
