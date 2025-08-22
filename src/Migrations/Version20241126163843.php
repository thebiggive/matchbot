<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Assertion;

/**
 * MAT-391 – delete pledges loaded in advance of CC24 that are no longer available.
 */
final class Version20241126163843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete pledges loaded in advance of CC24 that are no longer available';
    }

    public function up(Schema $schema): void
    {
        /** @var array{array{campaignId: int, campaignFundingId: int, fundId: int}} $deleteSets */
        $deleteSets = [ // @phpstan-ignore varTag.nativeType
            [
                'campaignId' => 7438, // SF a056900002TPTRqAAP
                'campaignFundingId' => 31205,
                'fundId' => 25353, // SF a0A69000043DEY6EAO
                'amount' => 500,
            ],
            [
                'campaignId' => 7836, // SF a056900002TPTfAAAX
                'campaignFundingId' => 33424,
                'fundId' => 27195, // SF a0AWS000001QOFq2AO
                'amount' => 336.5,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32828,
                'fundId' => 26696, // SF a0A69000043D6L1EAK
                'amount' => 100,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32829,
                'fundId' => 26697, // SF a0A69000043D6OAEA0
                'amount' => 100,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32830,
                'fundId' => 26698, // SF a0A69000043D6ZwEAK
                'amount' => 100,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32831,
                'fundId' => 26699, // SF a0A69000043D7VWEA0
                'amount' => 500,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32832,
                'fundId' => 26700, // SF a0A69000043DFzHEAW
                'amount' => 110,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32833,
                'fundId' => 26701, // SF a0A69000043DGSAEA4
                'amount' => 100,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32835,
                'fundId' => 26703, // SF a0A69000043DLRpEAO
                'amount' => 750,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32834,
                'fundId' => 26702, // SF a0A69000043DLQnEAO
                'amount' => 1_000,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32836,
                'fundId' => 26704, // SF a0A69000043DMO3EAO
                'amount' => 200,
            ],
            [
                'campaignId' => 7735, // SF a056900002TPVCLAA5
                'campaignFundingId' => 32838,
                'fundId' => 26706, // SF a0AWS00000164gH2AQ
                'amount' => 300,
            ],
            [
                'campaignId' => 7888, // SF a056900002TPUjnAAH
                'campaignFundingId' => 33724,
                'fundId' => 27446, // SF a0A69000043DEIMEA4
                'amount' => 3_750,
            ],
            [
                'campaignId' => 7306, // SF a056900002TPUifAAH
                'campaignFundingId' => 30583,
                'fundId' => 24843, // SF a0A69000043DOjcEAG
                'amount' => 2_500,
            ],
        ];

        Assertion::count($deleteSets, 14);

        foreach ($deleteSets as $deleteSet) {
            $this->addSql('DELETE FROM Campaign_CampaignFunding WHERE campaign_id = :campaignId AND campaignfunding_id = :campaignFundingId LIMIT 1', $deleteSet);
            $this->addSql('DELETE FROM CampaignFunding WHERE id = :campaignFundingId AND fund_id = :fundId AND amount = :amount LIMIT 1', $deleteSet);
            $this->addSql('DELETE FROM Fund WHERE fundType = "pledge" AND id = :fundId LIMIT 1', $deleteSet);
        }
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
