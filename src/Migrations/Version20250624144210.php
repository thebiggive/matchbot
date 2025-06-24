<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250624144210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add total_fundraising_target field to campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE Campaign ADD total_fundraising_target_amountInPence INT NOT NULL,  ADD total_fundraising_target_currency VARCHAR(3) NOT NULL;
            UPDATE Campaign SET total_fundraising_target_currency = 'GBP' where 1;
            SQL
);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP total_fundraising_target_amountInPence, DROP total_fundraising_target_currency');
    }
}
