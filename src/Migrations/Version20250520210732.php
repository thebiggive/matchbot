<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250520210732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create view showing a summary of all donations, joined with campaigns and charities';
    }

    public function up(Schema $schema): void
    {
        // no-op - previously this was creating a view but that failed in one environment due to permissions issue.
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
        drop view `donation-summary-no-orm`;
        SQL
        );
    }
}
