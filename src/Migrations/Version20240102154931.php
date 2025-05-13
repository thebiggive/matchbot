<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2537 – Clear email address for one donation
 */
final class Version20240102154931 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Clear email address for one donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation SET donorEmailAddress = null
            WHERE id = 519552
            AND uuid = '98483dd2-3e05-4a29-9bda-4d5a2afe99c7'
            LIMIT 1
EOT);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
