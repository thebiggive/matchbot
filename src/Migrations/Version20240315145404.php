<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240315145404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Donation.giftaid non-null';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE Donation SET giftAid = 0 where giftAid is null;
        SQL
        );

        $this->addSql(<<<SQL
            ALTER TABLE Donation modify giftAid tinyint(1) NOT NULL;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back");
    }
}
