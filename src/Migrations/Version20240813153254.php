<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-368 pt ii: Fix an overzealously patched pledge.
 */
final class Version20240813153254 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Move a pledge link back to the correct Fund';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE CampaignFunding
            SET fund_id = 24106
            WHERE id = 29189 AND amount = 7500.00 AND allocationOrder = 100
            LIMIT 1
            SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
