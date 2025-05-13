<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20231208122432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Similar to migration 20231207104909 except that the first part of that had a mistake and did not match any donation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '324ec5b4-8f53-45fd-b90a-b25f97e55219'
            AND transactionId = 'pi_3OJvXSKkGuKkxwBN0oD5tfxe' -- https://dashboard.stripe.com/connect/transfers/pi_3OJvXSKkGuKkxwBN0oD5tfxe
                                                              -- Version20231207104909 had wrong transactionId here so did not match any donation.
            LIMIT 1
            EOT
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back!");
    }
}
