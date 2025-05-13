<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250228124233 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return '';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'production') {
            // cancelling all mandates to avoid allow unique index to be created.
            $this->addSql(<<<'SQL'
                UPDATE RegularGivingMandate 
                SET 
                    status='cancelled',
                    RegularGivingMandate.cancelledAt = NOW(),
                    RegularGivingMandate.cancellationReason = 'Migration 20250228124233 cancelling all mandates to add unique constraint',
                    RegularGivingMandate.cancellationType = 'BigGiveCancelled'                
                where id > 0
                SQL
                );
        }

        // workaround for MySQL not supporting partial indexes. I don't think we can generate this via a Doctrine
        // annotation. Based on similar index email_if_password used in identity service.

        $this->addSql(sql: <<<'SQL'
            CREATE UNIQUE INDEX person_id_if_active ON RegularGivingMandate(
                (CASE WHEN RegularGivingMandate.status in ('active', 'pending') THEN concat(personid, ':', campaignId) END)
            );
        SQL
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX person_id_if_active ON RegularGivingMandate');
    }
}
