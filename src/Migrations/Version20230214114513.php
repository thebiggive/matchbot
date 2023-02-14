<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230214114513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update'
            WHERE
                Donation.gift_aid = 1 AND
                donationStatus = 'Paid' AND
                Donation.campaign_id IN (
                    SELECT Campaign.id FROM Campaign 
                        JOIN Charity on Campaign.charity_id = Charity.id 
                                       WHERE Charity.salesforceId = "0016900002to3G9AAI"
                )
        SQL
);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration();
    }
}
