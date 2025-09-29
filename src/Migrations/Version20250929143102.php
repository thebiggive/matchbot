<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250929143102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unwanted donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM DonorAccount where DonorAccount.stripeCustomerId = 'cus_T7zt2EunTf9Tjc' LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
