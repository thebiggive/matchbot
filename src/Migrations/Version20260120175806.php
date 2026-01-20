<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120175806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NAME ON Charity (name)');
        $this->addSql('CREATE FULLTEXT INDEX FULLTEXT_NAME ON Campaign (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX FULLTEXT_NAME ON Charity');
        $this->addSql('DROP INDEX FULLTEXT_NAME ON Campaign');
    }
}
