<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Assertion;

/**
 * MAT-392 – delete & reduce more Funds loaded in advance of CC24 that are fully or partially unavailable.
 */
final class Version20241128165753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete / reduce Funds loaded in advance of CC24 that are fully or partially unavailable';
    }

    public function up(Schema $schema): void
    {
        /** @var array{array{campaignId: int, campaignFundingId: int, fundId: int, amount: float}} $deleteSets */
        $deleteSets = [ // @phpstan-ignore varTag.nativeType
            [
                'campaignId' => 8289, // SF a056900002TPSp9AAH
                'campaignFundingId' => 36212,
                'fundId' => 29542, // SF a0AWS000001R1p32AC
                'amount' => 900,
            ],
        ];

        $reduceAmountSets = [
            // campaign ID 8289:
            [
                'campaignFundingId' => 36202, // Found via `SELECT * FROM `CampaignFunding` INNER JOIN Campaign_CampaignFunding ON Campaign_CampaignFunding.campaignfunding_id = CampaignFunding.id WHERE CampaignFunding.fund_id = 24640 AND Campaign_CampaignFunding.campaign_id = 8289;`
                'fundId' => 24640, // Reed Foundation, SF a09WS000002mf2zYAA
                'oldAmount' => 2_000,
                'newAmount' => 1_100,
            ],
            // campaign ID 7735:
            [
                'campaignFundingId' => 32827, // Found via `SELECT * FROM `CampaignFunding` INNER JOIN Campaign_CampaignFunding ON Campaign_CampaignFunding.campaignfunding_id = CampaignFunding.id WHERE CampaignFunding.fund_id = 24655 AND Campaign_CampaignFunding.campaign_id = 7735;`
                'fundId' => 24655, // Childhood Trust, SF a0969000023AK4aAAG
                'oldAmount' => 7_500,
                'newAmount' => 4_240,
            ]
        ];

        Assertion::count($deleteSets, 1);
        Assertion::count($reduceAmountSets, 2);

        foreach ($deleteSets as $deleteSet) {
            $this->addSql('DELETE FROM Campaign_CampaignFunding WHERE campaign_id = :campaignId AND campaignfunding_id = :campaignFundingId LIMIT 1', $deleteSet);
            $this->addSql('DELETE FROM CampaignFunding WHERE id = :campaignFundingId AND fund_id = :fundId AND amount = :amount LIMIT 1', $deleteSet);
            $this->addSql('DELETE FROM Fund WHERE fundType = "pledge" AND id = :fundId LIMIT 1', $deleteSet);
        }

        foreach ($reduceAmountSets as $reduceAmountSet) {
            $this->addSql('UPDATE CampaignFunding SET amount = :newAmount, amountAvailable = :newAmount WHERE id = :campaignFundingId AND fund_id = :fundId AND amount = :oldAmount LIMIT 1', $reduceAmountSet);
        }
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
