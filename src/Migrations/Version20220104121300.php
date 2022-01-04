<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-223 - remaining CC21 data patches.
 */
final class Version20220104121300 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Update incorrect CC21 Gift Aid declarations and mark for re-pushing to Salesforce.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $updateIncorrectPostcode = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', donorHomePostcode = donorPostalAddress
WHERE uuid = :uuid AND transactionId = :paymentIntentId
LIMIT 1
EOT;

        $this->addSql($updateIncorrectPostcode, [
            'uuid' => '4f6804a9-6e2c-46de-9cb4-6bc59c380f90',
            'paymentIntentId' => 'pi_3K2RMiKkGuKkxwBN0S5Uy9mP',
        ]);
        $this->addSql($updateIncorrectPostcode, [
            'uuid' => 'd957f281-d71b-45ba-8593-cf12f9203d5b',
            'paymentIntentId' => 'pi_3K2YigKkGuKkxwBN0re3gWSI',
        ]);

        $updateDonorGAIncorrectlyClaimed = <<<EOT
UPDATE Donation
SET salesforcePushStatus = 'pending-update', giftAid = 0, tipGiftAid = 0,
    donorHomeAddressLine1 = NULL, donorHomePostcode = NULL
WHERE uuid = :uuid AND transactionId = :paymentIntentId
LIMIT 1
EOT;

        $this->addSql($updateDonorGAIncorrectlyClaimed, [
            'uuid' => 'b07e5700-ab97-4fd8-99d7-33c3fd5f0666',
            'paymentIntentId' => 'pi_3K2DQDKkGuKkxwBN0rEMw2oz',
        ]);
        $this->addSql($updateDonorGAIncorrectlyClaimed, [
            'uuid' => 'bd7a0b27-bddf-4728-9e39-facc0f6b4172',
            'paymentIntentId' => 'pi_3K3d9dKkGuKkxwBN1Dlln6TX',
        ]);
        $this->addSql($updateDonorGAIncorrectlyClaimed, [
            'uuid' => 'fe2c6084-61bc-4d65-b510-95cce859dc24',
            'paymentIntentId' => 'pi_3K1WhbKkGuKkxwBN0hXKeHh1',
        ]);
        $this->addSql($updateDonorGAIncorrectlyClaimed, [
            'uuid' => '5495a028-742e-4d71-9392-4a3cb722f595',
            'paymentIntentId' => 'pi_3K3i7MKkGuKkxwBN1WWThUP3',
        ]);
        $this->addSql($updateDonorGAIncorrectlyClaimed, [
            'uuid' => '3f79c7ef-d6d1-406f-a2c8-80e006d17312',
            'paymentIntentId' => 'pi_3K2YdQKkGuKkxwBN1SJh1OKF',
        ]);
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        // No safe un-fix -> no-op.
    }
}
