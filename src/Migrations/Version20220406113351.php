<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CLA-19 – prepare stuck and failed Gift Aid donations for a re-claim.
 */
final class Version20220406113351 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prepare stuck and failed Gift Aid donations for a re-claim';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
UPDATE Donation
SET tbgGiftAidRequestQueuedAt = NULL
WHERE
      tbgGiftAidRequestQueuedAt IS NOT NULL AND
      tbgGiftAidRequestConfirmedCompleteAt IS NULL
LIMIT 543
EOT);
    }

    public function down(Schema $schema): void
    {
        // No un-fix.
    }
}
