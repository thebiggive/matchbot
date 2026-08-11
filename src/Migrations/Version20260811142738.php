<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-3357 Add index to Donation to optimise donation amount summing.
 */
final class Version20260811142738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index to Donation to optimise donation amount summing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX campaign_currency_status_amount ON Donation (campaign_id, currencyCode, donationStatus, amount)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX campaign_currency_status_amount ON Donation');
    }
}
