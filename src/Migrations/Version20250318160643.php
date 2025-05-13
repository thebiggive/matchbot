<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250318160643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column Donation.giftAidRemovedAt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation ADD giftAidRemovedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP giftAidRemovedAt');
    }
}
