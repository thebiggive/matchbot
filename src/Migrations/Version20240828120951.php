<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240828120951 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment method ID for use in regular giving to DB';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount ADD regularGivingPaymentMethod VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE DonorAccount DROP regularGivingPaymentMethod');
    }
}
