<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240809143435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE RegularGivingMandate
                ADD activeFrom DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD status VARCHAR(255) NOT NULL
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate DROP activeFrom, DROP status');
    }
}
