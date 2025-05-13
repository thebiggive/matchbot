<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240522134733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unwanted customer accounts (BG2-2618 & BG2-2633)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM `DonorAccount` 
            WHERE stripeCustomerId IN ('cus_Q6z8AlZeDZN7XE', 'cus_Q97wauMHN5VSmi', 'cus_PyLyjSXfCXTsTr', 'cus_PyZ4LHTGMHq7Qz')
            LIMIT 4; 
        SQL
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
