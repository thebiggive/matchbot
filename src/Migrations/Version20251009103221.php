<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20251009103221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update charity ID for one campaign to match change made manually in SF';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Campaign set charity_id = 19625 where Campaign.salesforceId='a05WS000005wbVlYAI' AND Campaign.id = 29819 limit 1;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Campaign set charity_id = 2485 where Campaign.salesforceId='a05WS000005wbVlYAI' AND Campaign.id = 29819 limit 1;
        SQL
        );
    }
}
