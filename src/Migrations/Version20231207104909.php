<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231207104909 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove gift aid from one donation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '324ec5b4-8f53-45fd-b90a-b25f97e55219' 
            AND transactionId = 'tr_3OJvXSKkGuKkxwBN0NVpIJSU' -- https://dashboard.stripe.com/connect/transfers/tr_3OJvXSKkGuKkxwBN0NVpIJSU
            LIMIT 1
            EOT
        );

        $this->addSql( <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
            WHERE uuid = '5453a2ee-ce04-434b-9bbb-887c8d4762c4' 
            AND transactionId = 'pi_3OHR8CKkGuKkxwBN0SwFV9Ph' -- https://dashboard.stripe.com/connect/transfers/pi_3OHR8CKkGuKkxwBN0SwFV9Ph
            LIMIT 1
            EOT
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception("no going back!");
    }
}
