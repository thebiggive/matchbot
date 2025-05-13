<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-300 – Include some CC22 donations for belated, internal Gift Aid claims.
 */
final class Version20230523154501 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Include some CC22 donations for Gift Aid claims';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation SET tbgShouldProcessGiftAid = 1
            WHERE campaign_id = 4216 AND
                  giftAid = 1 AND
                  donationStatus = 'Paid'
            LIMIT 6;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
