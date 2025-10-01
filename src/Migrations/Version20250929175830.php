<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929175830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unwanted donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM DonorAccount where stripeCustomerId = "cus_SyVojkUam4cxv5" LIMIT 1');
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
