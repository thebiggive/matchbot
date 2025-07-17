<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-422 – Shorten Fund.slug, matching the Salesforce field length.
 */
final class Version20250716085905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shorten Fund.slug';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund CHANGE slug slug VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund CHANGE slug slug VARCHAR(255) DEFAULT NULL');
    }
}
