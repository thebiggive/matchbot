<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240902110511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete unused fee cover related columns where not already deleted';
    }

    public function up(Schema $schema): void
    {
        /** We already deleted these columns in non-prod envs but then decided to delay deleting them in
         * @see Version20240821103038 which was edited to comment out some lines after it ran in staging.
         *
         * Attempting to delete the same column twice throws, so skip this migration in envs where its already deleted.
         */
        if(! $schema->getTable('Donation')->hasColumn('feeCoverAmount')) {
            return;
        }

        $this->addSql('ALTER TABLE Campaign DROP feePercentage');
        $this->addSql('ALTER TABLE Donation DROP feeCoverAmount');
        $this->addSql('ALTER TABLE Charity DROP updateFromSFRequiredSince');
    }

    public function down(Schema $schema): void
    {
        throw new \Exception("no going back");
    }
}
