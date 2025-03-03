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
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'production') {
            // deleting mandates to avoid allow unique index to be created.
            $this->addSql('DELETE from Donation WHERE mandate_id is not null');
            $this->addSql('DELETE from RegularGivingMandate where id > 0');
        }

        // workaround for MySQL not supporting partial indexes. I don't think we can generate this via a Doctrine
        // annotation. Based on similar index email_if_password used in identity service.

        // This is causing a crash when we run doctrine:migrations:diff - see https://github.com/doctrine/dbal/issues/5306 and
        // possible solution at https://github.com/doctrine/dbal/pull/6811

        $this->addSql(sql: <<<'SQL'
            CREATE UNIQUE INDEX person_id_if_active ON RegularGivingMandate(
                (CASE WHEN RegularGivingMandate.status in ('active', 'pending') THEN concat(personid, ':', campaignId) END)
            );
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX person_id_if_active ON RegularGivingMandate');
    }
}
