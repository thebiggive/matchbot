<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240815143227 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Fake Regular Giving for testing on staging';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'staging') {
            return;
        }
        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('822fc5fe-5b15-11ef-8ad7-9bef561f0ecb', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 100, 'GBP', 1, '2024-01-01', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('944afd4e-5b15-11ef-89ba-f3038aaec362', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 200, 'GBP', 2, '2024-02-02', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('e542bbc4-5b15-11ef-96c3-5389d26dc7e8', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 300, 'GBP', 3, '2024-03-03', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('f47ddaec-5b15-11ef-87fb-b76231298d09', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 400, 'GBP', 4, '2024-04-04', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-11ef-a7d0-d3e4527a6yf1', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 500, 'GBP', 5, '2024-05-05', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-11ef-a7d0-d3e4529a62f1', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 600, 'GBP', 6, '2024-06-06', 'active')
            SQL
        );

        $this->addSql(<<<'SQL'
            INSERT INTO RegularGivingMandate (uuid, campaignId, charityId, giftAid, salesforceLastPush, salesforcePushStatus, salesforceId, createdAt, updatedAt, personid, amount_amountInPence, amount_currency, dayOfMonth, activeFrom, status) values 
            ('a8d9291c-589e-71ef-a7d0-d3e4527a62f1', 'a056900002SEW34AAH', '0016900002bned5AAA', 1, null, 'pending-create', null, NOW(), NOW(), '1ee060ec-969f-635c-9e12-71a210b51211', 600, 'GBP', 7, NOW(), 'active')
            SQL
        );

    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
