<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-469 - Amend email address on donation from CC25
 */
final class Version20251208123119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation 
                SET donorEmailAddress = (
                    SELECT donorEmailAddress from Donation where uuid = '3d57e857-4830-4088-9edd-18b32b4dccc1'
                )
                WHERE uuid = 'b59e65f7-3bae-47ce-94b2-5d0811b06917'
                LIMIT 1;
        SQL);
    }

    public function down(Schema $schema): void
    {
        // no un-patch
    }
}
