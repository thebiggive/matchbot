<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240716142043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MAT-368: replace fund details';
    }

    public function up(Schema $schema): void
    {
        $campaignsToUpdate = [
            'a056900002SETkhAAH' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
            'a056900002SEVhXAAX' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
            'a056900002SEVkqAAH' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],

            'a056900002SEVoiAAH' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
            'a056900002SEVpMAAX' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
            'a056900002SEVvtAAH' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],

            'a056900002SEW18AAH' => ['a0969000021y3rLAAQ', 'The Reed Foundation'],
            'a056900002SEW8YAAX' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
            'a056900002SMAFHAA5' => ['a0969000021yW3AAAU', 'Postcode Green Trust'],
        ];

        $this->addSql('START TRANSACTION');

        foreach ($campaignsToUpdate as $campaignSFID => $newFundDetails) {

            $newFundSFID = $newFundDetails['0'];
            $newFundName = $newFundDetails['1'];

            $query = <<<SQL
                UPDATE Fund 
                JOIN CampaignFunding on Fund.id = CampaignFunding.fund_id
                JOIN Campaign_CampaignFunding on Campaign_CampaignFunding.campaignfunding_id = CampaignFunding.id
                JOIN Campaign on Campaign.id = Campaign_CampaignFunding.campaign_id
                SET Fund.salesforceId = '$newFundSFID', Fund.name = '$newFundName' WHERE Campaign.salesforceId = '$campaignSFID'
            SQL;

            echo $query;
            echo "\n\n";

            $this->addSql($query
            );
        }

        $this->addSql('COMMIT');
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('No going back!');
    }
}
