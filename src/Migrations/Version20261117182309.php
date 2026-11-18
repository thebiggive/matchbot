<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-499 Add indexes to improve search/list and withdrawal release performance.
 */
final class Version20261117182309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes to improve search/list and withdrawal release performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX meta_campaign_search ON Campaign (metaCampaignSlug, isPublished, hidden, isMatched, relatedApplicationStatus, relatedApplicationCharityResponseToOffer)');
        $this->addSql('CREATE INDEX country_and_campaign ON CampaignLocation (countryName, campaign_id)');
        $this->addSql('CREATE INDEX region_and_campaign ON CampaignLocation (regionCode, campaign_id)');
        $this->addSql('CREATE INDEX donation_released_amount ON FundingWithdrawal (donation_id, releasedAt, amount)');
        $this->addSql('CREATE INDEX containment_composite ON UKLocationContainment (regionCode, ancestorRegionCode)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX meta_campaign_search ON Campaign');
        $this->addSql('DROP INDEX country_and_campaign ON CampaignLocation');
        $this->addSql('DROP INDEX region_and_campaign ON CampaignLocation');
        $this->addSql('DROP INDEX donation_released_amount ON FundingWithdrawal');
        $this->addSql('DROP INDEX containment_composite ON UKLocationContainment');
    }
}
