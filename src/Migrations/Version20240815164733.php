<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240815164733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove test regular giving mandate on staging with invalid uuid';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'staging') {
            return;
        }

        // having this in the DB seems to cause a Doctrine ORM crash when we load this mandate.
        $this->addSql('DELETE FROM RegularGivingMandate where NOT IS_UUID(uuid);');
    }

    public function down(Schema $schema): void
    {
        // no undo
    }
}
