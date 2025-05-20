<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250520210732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create view showing a summary of all donations, joined with campaigns and charities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                create definer = root@`%` view `donation-summary-no-orm` as
        select `Donation`.`id`             AS `id`,
               `Donation`.`salesforceId`   AS `salesforceId`,
               `Donation`.`createdAt`      AS `createdAt`,
               `Donation`.`amount`         AS `amount`,
               `Donation`.`donationStatus` AS `donationStatus`,
               `Charity`.`name`            AS `CharityName`,
               `Campaign`.`name`           AS `CampaignName`
        from ((`Donation` join `Campaign`
               on ((`Campaign`.`id` = `Donation`.`campaign_id`))) join `Charity`
              on ((`Charity`.`id` = `Campaign`.`charity_id`)));
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
        drop view `donation-summary-no-orm`;
        SQL
        );
    }
}
