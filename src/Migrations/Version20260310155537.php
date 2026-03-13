<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-479 Remove redundant Campaign amount fields that are replaced with stats table values & real fundings.
 */
final class Version20260310155537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove redundant Campaign amount fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP total_funding_allocation_amountInPence, DROP total_funding_allocation_currency, DROP amount_pledged_amountInPence, DROP amount_pledged_currency');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD total_funding_allocation_amountInPence BIGINT NOT NULL, ADD total_funding_allocation_currency VARCHAR(3) NOT NULL, ADD amount_pledged_amountInPence BIGINT NOT NULL, ADD amount_pledged_currency VARCHAR(3) NOT NULL');
    }
}
