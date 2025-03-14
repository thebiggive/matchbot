<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250129150903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RegularGivingMandate tbgComms and charityComms columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE RegularGivingMandate
                ADD tbgComms TINYINT(1) NOT NULL DEFAULT 0,
                ADD charityComms TINYINT(1) NOT NULL DEFAULT 0
            SQL
            );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate DROP tbgComms, DROP charityComms');
    }
}
