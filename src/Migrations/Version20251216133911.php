<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-472 – Align CampaignFunding's available balance with the fix from last week.
 */
final class Version20251216133911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct remaining out of sync fund balance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = 3110 WHERE id = 47947 AND amount = 10000 LIMIT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = 0 WHERE id = 47947 AND amount = 10000 LIMIT 1');
    }
}
