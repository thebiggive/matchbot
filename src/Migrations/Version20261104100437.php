<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20261104100437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reset fund ID 2 amount used to £0 in Regression environment';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Regression) {
            return;
        }

        $this->addSql('UPDATE CampaignFunding SET amountAvailable = amount WHERE id = 2');
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
