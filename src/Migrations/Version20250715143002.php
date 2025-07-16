<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-413 – Add search/list pin positions.
 */
final class Version20250715143002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add search/list pin positions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD pinPosition INT DEFAULT NULL, ADD championPagePinPosition INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP pinPosition, DROP championPagePinPosition');
    }
}
