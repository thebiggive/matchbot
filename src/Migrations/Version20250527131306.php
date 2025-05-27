<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250527131306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fix failure to push donations with redacted email to SF - we have validation for email address format';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE Donation 
                 SET Donation.donorEmailAddress = 'email-redacted@example.com' 
                 WHERE Donation.donorEmailAddress = 'email-redacted'
                 LIMIT 28"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE Donation 
                 SET Donation.donorEmailAddress = 'email-redacted' 
                 WHERE Donation.donorEmailAddress = 'email-redacted@example.com'
                 LIMIT 28"
        );
    }
}
