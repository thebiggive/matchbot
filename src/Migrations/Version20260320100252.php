<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-480: (Hopefully last adjustment needed) Adjust funding amounts available down based on this morning's
 * OUT OF SYNC FUNDS DETECTED slack message from Matchbot.
 *
 * This isn't perfect because if there happens to be a copy of one of these in PHP memory that is about to be saved
 * because of a donation being created or released at the moment this runs then that would overwrite the changes here,
 * but that's probably not very likely.
 *
 * Also not writing to the new $adjustmentLog field since using JSON_INSERT to atomically add an entry to that is something
 * I'd have to learn how to do and doesn't seem critical, especially since we're correcting for something that was also
 * not in that field.
 */
final class Version20260320100252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MAT-480 - Funding AmountsAvailable adjustments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 15.00, 0) WHERE id = 49320');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 25.00, 0) WHERE id = 49323');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 10.00, 0) WHERE id = 49337');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 1.00, 0) WHERE id = 49367');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 30.00, 0) WHERE id = 49393');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 500.00, 0) WHERE id = 49499');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 10.00, 0) WHERE id = 49811');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 10.00, 0) WHERE id = 50218');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 100.00, 0) WHERE id = 50223');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 20.00, 0) WHERE id = 50228');
        $this->addSql('UPDATE CampaignFunding SET amountAvailable = GREATEST(amountAvailable - 70.00, 0) WHERE id = 50262');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('No going back');
    }
}
