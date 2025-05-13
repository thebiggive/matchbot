<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-349 – remove a Gift Aid declaration from 1 donation.
 */
final class Version20231219130850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove a Gift Aid declaration from 1 donation, 19/12/23 patch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '52bfa743-43e6-480f-9720-a675bce6beae'
            AND transactionId = 'pi_3OIX1WKkGuKkxwBN0KG10w2G'
            LIMIT 1
            EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
