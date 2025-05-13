<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-275 – OPTIMIZE Donation and FundingWithdrawal
 */
final class Version20230110140053 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'OPTIMIZE Donation and FundingWithdrawal';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('OPTIMIZE TABLE Donation');
        $this->addSql('OPTIMIZE TABLE FundingWithdrawal');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-optimise.
    }
}
