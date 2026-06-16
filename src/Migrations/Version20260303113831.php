<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303113831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FundingWithdrawal.releasedAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE FundingWithdrawal ADD releasedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE FundingWithdrawal DROP releasedAt');
    }
}
