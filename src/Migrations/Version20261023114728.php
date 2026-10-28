<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20261023114728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CC-314 Update isPublished on out of sync CC26 campaigns';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() !== Environment::Staging) {
            return;
        }

        $this->addSql("UPDATE Campaign SET isPublished = 1 WHERE metaCampaignSlug = 'christmas-challenge-2026' AND isPublished = 0 AND relatedApplicationStatus = 'Approved' AND relatedApplicationCharityResponseToOffer = 'Accepted'");
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
