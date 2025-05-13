<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Domain\Donation;

final class Version20240611093804 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Prevent Gift Aid claims for a charity briefly onboarded who wish to claim manually';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Charity SET tbgClaimingGiftAid = 0, tbgApprovedToClaimGiftAid = 0
            WHERE salesforceId = '0011r00002HoaJgAAJ' AND id = 2602
            LIMIT 1;
        EOT);

        /**
         * Per {@see Donation::$tbgShouldProcessGiftAid} docs, this flag is set independently of actual
         * Gift Aid status and donation status, so the safest move to ensure any future patch won't
         * cause unexpected side effects is to set it false for *all* 629 donations to this campaign,
         * even Pendings.
         */
        $this->addSql(<<<EOT
            UPDATE Donation set tbgShouldProcessGiftAid = 0 WHERE campaign_id = 6331
            LIMIT 629;
        EOT);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
