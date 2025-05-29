<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20250520161231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-2890: Delete unwanted donor account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<SQL
            DELETE FROM DonorAccount WHERE DonorAccount.uuid = '1ed6fdf0-71d6-6ab0-98a0-11455be3c07b' LIMIT 1
            SQL

        );

        if (Environment::current() === Environment::Staging) {
            return; // in case of collisions between donation ids we're wanting to adjust in prod and any in staging.
        }

        $this->addSql(
            <<<SQL
            UPDATE Donation SET 
                               donorEmailAddress = 'email-redacted',
                                salesforcePushStatus = 'pending-update'
                            WHERE Donation.id in 
                                  (402803,515709,516912,517671,517996,600261,643364,643374,643381,643390,643410,690965,691217,691316,691471,840246,840262,840279,842583,854145,854163,1120434,1120546,1120594,1120646,1120782,1124055,1124079)
                            LIMIT 28;
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        // no going back
    }
}
