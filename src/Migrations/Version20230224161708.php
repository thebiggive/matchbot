<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230224161708 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Update status on refunded donations';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
        UPDATE Donation SET DonationStatus = 'Refunded' 
        WHERE Donation.transactionId IN (
                                         'pi_3KbTS4KkGuKkxwBN0ODatXZe',
                                         'pi_3KbWRSKkGuKkxwBN12LRWjpG',
                                         'pi_3KbPnyKkGuKkxwBN0udOmTbP'
                                        )
        LIMIT 3;
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
