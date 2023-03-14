<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-291 pt. 2: set Salesforce push status on 3 refunded donations.
 */
final class Version20230314142005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set Salesforce push status on 3 refunded donations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
        UPDATE Donation SET salesforcePushStatus = 'pending-update' 
        WHERE Donation.transactionId IN (
          'pi_3KbTS4KkGuKkxwBN0ODatXZe',
          'pi_3KbWRSKkGuKkxwBN12LRWjpG',
          'pi_3KbPnyKkGuKkxwBN0udOmTbP'
        )
        LIMIT 3;
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
