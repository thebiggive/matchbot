<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20221213144526 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remove gift aid added to donations by mistake';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                UPDATE Donation 
                set giftAid = 0, tipGiftAid = 0, salesforcePushStatus = 'pending-update'
                WHERE Donation.salesforceId IN ('a066900001vWmAaAAK', 'a066900001vWlRHAA0', 'a066900001vVkwZAAS') LIMIT 3
            SQL
        );
    }
    #[\Override]
    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
