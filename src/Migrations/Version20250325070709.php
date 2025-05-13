<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-410 – Patch 2 pledge types + orders
 */
final class Version20250325070709 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Patch 2 pledge types + orders';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $fundIds = [30751, 30752]; // SF pledge IDs a0AWS0000084tFp2AI, a0AWS0000084v4j2AA.
        $this->addSql(
            "UPDATE Fund SET fundType = 'topupPledge' WHERE id IN (:fundIds)",
            ['fundIds' => $fundIds],
            ['fundIds' => ArrayParameterType::INTEGER],
        );
        $this->addSql(
            'UPDATE CampaignFunding SET allocationOrder = 300 WHERE fund_id IN (:fundIds)',
            ['fundIds' => $fundIds],
            ['fundIds' => ArrayParameterType::INTEGER],
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
