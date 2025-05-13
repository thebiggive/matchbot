<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-278 fix invalid donor email addresses that have two dots (..com -> .com).
 * These emails were accidentally persisted after DON-698.
 */
final class Version20221203115436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MAT-278 fix invalid donor email addresses that have two dots (..com -> .com).
        These emails were accidentally persisted after DON-698.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation SET donorEmailAddress = REPLACE(donorEmailAddress, '..com', '.com')
EOT,[]);
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
