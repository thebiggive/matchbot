<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-318 – Add tbgApprovedToClaimGiftAid to Charity
 */
final class Version20230928150700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tbgApprovedToClaimGiftAid to Charity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity ADD tbgApprovedToClaimGiftAid TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Charity DROP tbgApprovedToClaimGiftAid');
    }
}
