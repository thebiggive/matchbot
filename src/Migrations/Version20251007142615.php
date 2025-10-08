<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251007142615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RegularGivingMandate.paymentDateOffsetMonths';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate ADD paymentDateOffsetMonths INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate DROP paymentDateOffsetMonths');
    }
}
