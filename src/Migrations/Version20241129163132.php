<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove Gift Aid info for a now-inactive charity following an organisational merge.
 */
final class Version20241129163132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Gift Aid info for a now-inactive charity following an organisational merge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE Charity SET hmrcReferenceNumber = NULL, tbgClaimingGiftAid = 0, tbgApprovedToClaimGiftAid = 0 WHERE id = 343 AND salesforceId = '0011r00002Hof4XAAR' LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
