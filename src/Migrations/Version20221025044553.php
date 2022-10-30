<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-266, MAT-267 – Add `Donation.paymentMethodType`.
 */
final class Version20221025044553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Donation.paymentMethodType';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('Donation')->hasColumn('paymentMethodType')) {
            $this->addSql('ALTER TABLE Donation ADD paymentMethodType VARCHAR(255) DEFAULT NULL');
        }

        // Removed after the fact because Production seems unable to complete
        // the transaction within the lock time.
//        $this->addSql("UPDATE Donation SET paymentMethodType = 'card' WHERE paymentMethodType IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Donation DROP paymentMethodType');
    }
}
