<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Money amounts used for targets have defaulted to zero for noow until we've pulled
 * them from SF. That's fine but the currencies have defaulted to '' which is not
 * a known currency, so causes an error when we try to hydrate the entities.
 */
final class Version20250624113321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set GBP currency for zero money amounts';
    }

    public function up(Schema $schema): void
    {
        // the below will actually affect all rows in the two tables, but the fact that the numbers are all zero shows
        // why it's OK to set the currency to GBP without sourcing it from anywhere.
        
        $this->addSql(<<<'SQL'
            update Campaign set total_funding_allocation_currency = 'GBP' where total_funding_allocation_amountInPence = 0;
            update Campaign set amount_pledged_currency = 'GBP' where amount_pledged_amountInPence = 0;
            update MetaCampaign set imf_campaign_target_override_currency = 'GBP' where imf_campaign_target_override_amountInPence = 0;
            update MetaCampaign set match_funds_total_currency = 'GBP' where match_funds_total_amountInPence = 0;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            update Campaign set total_funding_allocation_currency = '' where total_funding_allocation_amountInPence = 0;
            update Campaign set amount_pledged_currency = '' where amount_pledged_amountInPence = 0;
            update MetaCampaign set imf_campaign_target_override_currency = '' where imf_campaign_target_override_amountInPence = 0;
            update MetaCampaign set match_funds_total_currency = '' where match_funds_total_amountInPence = 0;
        SQL
        );
    }
}
