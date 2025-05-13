<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240722094007 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        /**
         * Donations will be looked up by this field for now to show them to customers, so needs index for performance:
         */
        return 'CREATE INDEX pspCustomerId ON Donation (pspCustomerId)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX pspCustomerId ON Donation (pspCustomerId)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX pspCustomerId ON Donation');
    }
}
