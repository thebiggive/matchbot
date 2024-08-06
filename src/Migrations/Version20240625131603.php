<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240625131603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set a payment method type on old donations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOT
            UPDATE Donation SET paymentMethodType = 'card'
            WHERE paymentMethodType IS NULL
            AND createdAt < '2024-01-01'
        EOT);
    }

    public function down(Schema $schema): void
    {
        // No un-patch.
    }
}
