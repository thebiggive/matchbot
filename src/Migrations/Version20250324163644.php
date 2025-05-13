<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250324163644 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'MAT-410: Adjust allocation orders for funds converted from pledge to topuppledge';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE CampaignFunding set allocationOrder = 300 -- 300 is the value of FundType::TopupPledge->allocationOrder()
            WHERE CampaignFunding.fund_id IN 
                 (SELECT id from Fund where Fund.fundType = 'topupPledge')
        SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
