<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-236 – remove temporary test donation & campaign records to prevent Production alarm noise.
 */
final class Version20220308132408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove temporary test donation & campaign records';
    }

    public function up(Schema $schema): void
    {
        $campaignId = 3076;
        $this->addSql(
            'DELETE FROM Donation WHERE uuid = :uuid AND campaign_id = :campaignId LIMIT 1',
            [
                'campaignId' => $campaignId,
                'uuid' => '846b5692-4ccb-4e88-9cf3-ea0223951ee7',
            ],
        );
        $this->addSql(
            'DELETE FROM Campaign WHERE id = :campaignId AND salesforceId = :salesforceId LIMIT 1',
            [
                'campaignId' => $campaignId,
                'salesforceId' => 'a0569000029jOkUAAU',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No un-fix
    }
}
