<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240807113023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear wrong HMRC ref number that was already removed from SF';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sql: <<<SQL
            UPDATE Charity set hmrcReferenceNumber = null where salesforceId = '0016900002tly4dAAA' LIMIT 1;
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back");
    }
}
