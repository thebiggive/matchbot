<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * As we had a bug that meant we took two donations in quick sucession when this mandate was set up and we had
 * to refund one of them, if we do nothing the future donations will be one month later than expected. This
 * adjusts them to happen when they would as if that second refunded donation was never made.
 */
final class Version20251007142800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move donations one month earlier for one mandate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
    UPDATE RegularGivingMandate SET RegularGivingMandate.paymentDateOffsetMonths = -1
    WHERE RegularGivingMandate.uuid = '28b5918b-31aa-473d-bcd0-b1ad5ab4f3cb' LIMIT 1
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
    UPDATE regularGivingMandate SET RegularGivingMandate.paymentDateOffsetMonths = 0 
    WHERE RegularGivingMandate.uuid = '28b5918b-31aa-473d-bcd0-b1ad5ab4f3cb' LIMIT 1
SQL
        );
    }
}
