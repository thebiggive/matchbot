<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20250217175219 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add field DonorAccount ADD homeIsOutsideUK';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD homeIsOutsideUK TINYINT(1) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount DROP homeIsOutsideUK');
    }
}
