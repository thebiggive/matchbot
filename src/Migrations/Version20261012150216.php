<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Domain\UKLocation;

final class Version20261012150216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pre-fill UKLocationContainment with relationships between UK locations to facilitate search';
    }

    public function up(Schema $schema): void
    {
        $now = new \DateTimeImmutable()->format('c');

        $locations = UKLocation::allLocations();
        foreach ($locations as $location) {
            $descendants = UKLocation::allDescendantsOf($location);
            foreach ([$location, ...$descendants] as $descendant) {
                $this->addSql(
                    'INSERT INTO UKLocationContainment (regionCode, ancestorRegionCode, createdAt, updatedAt) values (?, ?, ?, ?)',
                    [$descendant->code, $location->code, $now, $now]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM UKLocationContainment where id > 0');
    }
}
