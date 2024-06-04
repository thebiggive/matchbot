<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240604153509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Submit CC23 GA';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE Charity set tbgClaimingGiftAid = 1 WHERE salesforceId = \'0011r00002Hoa6wAAB\' and id=44
                 LIMIT 1;'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
        'UPDATE Charity set tbgClaimingGiftAid = 0 WHERE salesforceId = \'0011r00002Hoa6wAAB\' and id=44
                 LIMIT 1;'
        );
    }
}
