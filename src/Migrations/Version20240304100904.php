<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240304100904 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Avoid claiming gift aid for specific donations where charity is planning to claim it for themselves';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
                        UPDATE Donation set Donation.tbgShouldProcessGiftAid = 0 
                        WHERE Donation.campaign_id = 5483
                        AND donationStatus != 'Paid'
                        LIMIT 46
                        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
                        UPDATE Donation set Donation.tbgShouldProcessGiftAid = 1 
                        WHERE Donation.campaign_id = 5483
                        AND donationStatus != 'Paid'
                        LIMIT 46
                        SQL
        );
    }
}
