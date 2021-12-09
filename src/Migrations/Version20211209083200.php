<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-216, MAT-217 - CC21 data patches.
 */
final class Version20211209083200 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Update incorrect data from CC21 edge cases and mark for re-pushing to Salesforce.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $updateTipPartiallyRefundedDonation = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', tipAmount = 5
WHERE uuid = '096fe6fd-d8b0-42fd-806f-03bbdc54c092' AND transactionId = 'pi_3K1ZFYKkGuKkxwBN0IPLOiWS'
LIMIT 1
EOT;
        $this->addSql($updateTipPartiallyRefundedDonation);

        $updateDonorGAIncorrectlyClaimed = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0
WHERE uuid = '2417b8db-89b1-438b-a053-33e77c58303c' AND transactionId = 'pi_3K3zlfKkGuKkxwBN14bQcAKu'
LIMIT 1
EOT;
        $this->addSql($updateDonorGAIncorrectlyClaimed);
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        // No safe un-fix -> no-op.
    }
}
