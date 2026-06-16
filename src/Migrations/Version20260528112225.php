<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528112225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Based isPublished on ready for campaigns not created more recently than the new logic';
    }

    public function up(Schema $schema): void
    {
        // Patch all campaigns before the migration that set this unreliably went to Production (and a few more by
        // setting it at a slightly earlier time than the merge of https://github.com/thebiggive/matchbot/pull/1942)
        $this->addSql('UPDATE Campaign SET isPublished = ready WHERE createdAt < "2026-04-29 12:39:00"');
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
