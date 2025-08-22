<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;


final class Version20250822144545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update SCW 2025 fundings';
    }

    public function up(Schema $schema): void
    {
        $charitiesWithNewFundingIds = [
            ['Teen Action', 'a09WS000009eqqnYAA'],
            ['Community Money Advice', 'a09WS000009eqqnYAA'],
            ['Soundabout', 'a09WS000009gVqDYAU'],
            ['Living Paintings', 'a09WS000009gVqDYAU'],
            ['Angel Shed Theatre Company', 'a09WS000009gVqDYAU'],
            ['The Katie Piper Foundation', 'a09WS000009gVqDYAU'],
            ['Yes Futures', 'a09WS000009eqqnYAA'],
            ['Stem4', 'a09WS000009eqqnYAA'],
            ['Mindout Lgb&t Mental Health Project', 'a09WS000009gVqDYAU'],
            ['Small Steps', 'a09WS000009eqqnYAA'],
            ['No.5 Young People', 'a09WS000009OmBBYA0'],
            ['Sister Circle', 'a09WS000009eqqnYAA'],
            ['Home-Start Essex', 'a09WS000009OmBBYA0'],
            ['Beauty Banks', 'a09WS000009OmBBYA0'],
            ['Dementia Carers Count', 'a09WS000009OmBBYA0'],
            ['The Log Cabin Charity', 'a09WS000009gVqDYAU'],
            ['FEAST WITH US', 'a09WS000009gVqDYAU'],
            ['THE NEW NORMAL', 'a09WS000009eqqnYAA'],
            ['BRIGHTON PEOPLE\'S THEATRE CIO', 'a09WS000009gVqDYAU'],
            ['CORINNE BURTON MEMORIAL TRUST', 'a09WS000009gVqDYAU'],
            ['LATCH WELSH CHILDREN\'S CANCER CHARITY', 'a09WS000009OmBBYA0'],
            ['IT GETS BETTER UK', 'a09WS000009OmBBYA0'],
            ['FOREVER COLOURS CHILDREN\'S HOSPICE', 'a09WS000009gVqDYAU'],
            ['FRIENDS OF NETTLEBED SCHOOL', 'a09WS000009gVqDYAU'],
            ['SNAPS Yorkshire CIO', 'a09WS000009OmBBYA0'],
            ['NATIONAL NETWORK FOR THE EDUCATION OF CARE LEAVERS', 'a09WS000009eqqnYAA'],
            ['Shiloh Rotherham', 'a09WS000009OmBBYA0'],
        ];

        foreach ($charitiesWithNewFundingIds as [$charityName, $newFundID]) {
            $this->addSql(<<<SQL
                UPDATE CampaignFunding SET CampaignFunding.fund_id = (SELECT id from Fund WHERE Fund.salesforceId = '$newFundID')
                WHERE (CampaignFunding.id) IN (SELECT Campaign_CampaignFunding.campaignfunding_id
                    FROM Campaign_CampaignFunding
                    WHERE campaign_id IN (SELECT Campaign.id
                        FROM Campaign
                        JOIN Charity on Campaign.charity_id = Charity.id
                        WHERE Charity.name = :charityName
                        AND Campaign.metaCampaignSlug = 'small-charity-week-2025'))
            SQL,
                ['charityName' => $charityName]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // no going back.
    }
}
