<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250409105405 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'CREATE TABLE EmailVerificationToken';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE EmailVerificationToken (
                id INT AUTO_INCREMENT NOT NULL,
                createdAt DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                emailAddress VARCHAR(255) NOT NULL,
                randomCode VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE EmailVerificationToken');
    }
}
