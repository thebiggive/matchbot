<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318150431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-allocate matching from over-matched pledge to not over matched pledge';
    }

    public function up(Schema $schema): void
    {
        // campaignFunding 49812 is the that still has funds available
        // campaignFunding 49811 is has zero available according to its stats but really was negative available as we've somehow withdrawn more than it has.
        $this->addSql('UPDATE FundingWithdrawal SET FundingWithdrawal.campaignFunding_id = 49812 WHERE FundingWithdrawal.id = 1220042');
    }

    public function down(Schema $schema): void
    {

        $this->addSql('UPDATE FundingWithdrawal SET FundingWithdrawal.campaignFunding_id = 49811 WHERE FundingWithdrawal.id = 1220042');
    }
}
