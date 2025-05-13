<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218162809 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add cancellation related properties to regular giving mandate';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE RegularGivingMandate 
                ADD cancellationType VARCHAR(50) DEFAULT NULL, 
                ADD cancellationReason VARCHAR(500) DEFAULT NULL, 
                ADD cancelledAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
            SQL
            );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE RegularGivingMandate DROP cancellationType, DROP cancellationReason, DROP cancelledAt'
        );
    }
}
