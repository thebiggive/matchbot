<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2990 Update a home address for Gift Aid on one donation
 */
final class Version20251015143752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update a home address for Gift Aid on one donation';
    }

    public function up(Schema $schema): void
    {
        // Update Donation with txn ID pi_3SGGPqKkGuKkxwBN0Ii4G0Fx to make home/Gift Aid address match pi_3SGGRxKkGuKkxwBN1lcesThM
        $this->addSql(<<<EOT
            UPDATE Donation d1
            INNER JOIN Donation d2 ON d2.transactionId = 'pi_3SGGRxKkGuKkxwBN1lcesThM'
            SET 
                d1.donorHomeAddressLine1 = d2.donorHomeAddressLine1,
                d1.donorHomePostcode = d2.donorHomePostcode,
                d1.salesforcePushStatus =  'pending-update'
            WHERE d1.transactionId = 'pi_3SGGPqKkGuKkxwBN0Ii4G0Fx'
            LIMIT 1
        EOT);
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
