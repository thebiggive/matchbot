<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-442 – Add Fund.slug.
 */
final class Version20250716083357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Fund.slug';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund ADD slug VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Fund DROP slug');
    }
}
