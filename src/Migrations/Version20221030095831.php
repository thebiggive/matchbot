<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix remaining missing payment method types following field addition.
 */
final class Version20221030095831 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Fix remaining missing payment method types following field addition';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // Removed after the fact because Production seems unable to complete
        // the transaction within the lock time.
//        $this->addSql("UPDATE Donation SET paymentMethodType = :card WHERE paymentMethodType IS NULL", ['card' => 'card']);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
