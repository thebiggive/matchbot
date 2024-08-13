<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20240812112954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fake regular giving mandate in staging for manual test purposes';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'staging') {
            return;
        }

        // Add three mandates for Barney in staging for testing

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-11ef-a7d0-d3e4527a62f1', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ed64ec0-66d8-688c-bd70-9ddbe3e97587', 500, 'GBP', 12, NOW(), 'active')
            SQL
            );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-11ef-a7d0-d3e4527a62f2', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ed64ec0-66d8-688c-bd70-9ddbe3e97587', 1500, 'GBP', 12, NOW(), 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-11ef-a7d0-d3e4527a62f3', 'a056900002SEW34AAH', '0016900002bned5AAA', 0, null, 'pending-create', null, NOW(), NOW(), '1ed64ec0-66d8-688c-bd70-9ddbe3e97587', 2000, 'GBP', 12, NOW(), 'active')
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'staging') {
            return;
        }

        $this->addSql("DELETE FROM RegularGivingMandate where uuid LIKE 'a8d9291c-589e-11ef-a7d0-d3e4527a62f%'");
    }
}
