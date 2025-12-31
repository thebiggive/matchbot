<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-472 Patch historic excessive FundingWithdrawals.
 */
final class Version20251230153004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patch historic excessive FundingWithdrawals';
    }

    public function up(Schema $schema): void
    {
        // Donation 546943 / dcec4e60-8969-4f40-80f0-35bb5b7184af from 2022 was for £450.
        $this->addSql('UPDATE FundingWithdrawal SET amount = 450, updatedAt = NOW() WHERE id = 463414 AND amount = 500 LIMIT 1');
        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'pending-update', updatedAt = NOW() WHERE id = 546943 LIMIT 1");

        // Donation 1048809 / c563fb10-ccbd-41d0-95c6-5a4e14596990 from 2024 was for £3,000. The later withdrawal
        // from CampaignFunding ID 32943 is all extra funds beyond the total so we should zero it.
        $this->addSql('UPDATE FundingWithdrawal SET amount = 0, updatedAt = NOW() WHERE id = 929427 AND amount = 1269 LIMIT 1');
        $this->addSql("UPDATE Donation SET salesforcePushStatus = 'pending-update', updatedAt = NOW() WHERE id = 1048809 LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE FundingWithdrawal SET amount = 500, updatedAt = NOW() WHERE id = 463414 AND amount = 450 LIMIT 1');
        $this->addSql('UPDATE FundingWithdrawal SET amount = 1269, updatedAt = NOW() WHERE id = 929427 AND amount = 0 LIMIT 1');
    }
}
