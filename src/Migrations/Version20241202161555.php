<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241202161555 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add Campaign.regularGivingCollectionEnd';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign ADD regularGivingCollectionEnd DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Campaign DROP regularGivingCollectionEnd');
    }
}
