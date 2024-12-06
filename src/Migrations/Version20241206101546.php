<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-394 – correct Gift Aid claimed in error.
 */
final class Version20241206101546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct Gift Aid claimed in error';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
WHERE uuid = '549b259f-02d6-4b68-939d-c297d1b88076' AND transactionId = 'pi_3QSQlcKkGuKkxwBN1iTNDzHq'
LIMIT 1
EOT
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
