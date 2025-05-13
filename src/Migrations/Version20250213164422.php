<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250213164422 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add is matched bool field to regular giving mandate';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate ADD isMatched TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE RegularGivingMandate ALTER isMatched DROP DEFAULT');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RegularGivingMandate DROP isMatched');
    }
}
