<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MAT-377 – Patch one donation where twin pushes caused a status blip.
 */
final class Version20241016110231 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Patch one donation where twin pushes caused a status blip';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $updateSql = <<<EOT
            UPDATE Donation
            SET salesforcePushStatus = 'pending-update'
            WHERE salesforceId = 'a06WS000005mSwrYAE'
            LIMIT 1
        EOT;
        $this->addSql($updateSql);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
