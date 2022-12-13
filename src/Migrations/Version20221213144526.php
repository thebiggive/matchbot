<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20221213144526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove gift aid added to donations by mistake';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE Donation set giftAid = 0 where Donation.salesforceId IN ("a066900001vWmAaAAK", "a066900001vWlRHAA0", "a066900001vVkwZAAS") LIMIT 3');
    }
    public function down(Schema $schema): void
    {
        // no going back
    }
}
