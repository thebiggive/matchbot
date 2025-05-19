<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MatchBot\Application\Environment;

final class Version20250519093230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cancel staging mandates that were cancelled erroneously by code running in regtest environment ';
    }

    public function up(Schema $schema): void
    {
        if (Environment::current() === Environment::Production) {
            return;
        }

        // to generate the list of UUIDs below run
        // SELECT GROUP_CONCAT(uuid SEPARATOR "', '") FROM `RegularGivingMandate`
        // WHERE RegularGivingMandate.status = "active" and
        // RegularGivingMandate.activeFrom < "2025-05-08" ORDER BY `RegularGivingMandate`.`activeFrom` DESC;
        // in staging env

        $this->addSql("UPDATE RegularGivingMandate SET 
                                cancellationType = 'BigGiveCancelled',
                                cancellationReason = 
                                    'Staging mandate cancelled because payment method likely erronesouly detatched from stripe by matchbot in regtest environment',
                                status = 'cancelled' where uuid in (
                                                                    '6ff4f03a-1394-4e6f-ac4c-463428a44442',
                                                                    'd56d82e1-4c5b-4188-90df-ddc9a57bfe31',
                                                                    '1c782039-9582-4839-a327-a9874dd2c201',
                                                                    '45789631-a0fa-4eae-b27d-87e8c905ffaa',
                                                                    'bee0e495-b299-452e-862a-7fbaaa3c9489',
                                                                    '4dfee484-3525-480f-a139-9d7a6feaff47',
                                                                    '8e584daa-e80f-4a2e-83a4-d8ab6379551f',
                                                                    '7b0a449d-5222-4974-b4c2-e6a8d3b3866c',
                                                                    '5a0d6f48-df1e-486e-9c4e-1304afc97069',
                                                                    '73372a81-037d-43eb-bd3b-5e80092f0057',
                                                                    'ee03a79b-0b96-408b-9a64-9dbc7407da31',
                                                                    '06dd48c2-ac83-4a50-a6f6-e9260c6d0788',
                                                                    '38afbd7a-cad5-4ad7-b39d-0a77f2086989', 
                                                                    '3b47e46e-d059-4e2a-a4c2-20cbf63ed055',
                                                                    'bff76789-bbf7-4c52-b6ba-9add045b1a77',
                                                                    'c7d337e4-4bb4-4747-8d75-09e6aaef8446')
                                                                    ");
    }

    public function down(Schema $schema): void
    {
        if (Environment::current() === Environment::Production) {
            return;
        }

        $this->addSql("UPDATE RegularGivingMandate SET 
                                cancellationType = null,
                                cancellationReason = null,
                                status = 'active' where uuid in (
                                                                    '6ff4f03a-1394-4e6f-ac4c-463428a44442',
                                                                    'd56d82e1-4c5b-4188-90df-ddc9a57bfe31',
                                                                    '1c782039-9582-4839-a327-a9874dd2c201',
                                                                    '45789631-a0fa-4eae-b27d-87e8c905ffaa',
                                                                    'bee0e495-b299-452e-862a-7fbaaa3c9489',
                                                                    '4dfee484-3525-480f-a139-9d7a6feaff47',
                                                                    '8e584daa-e80f-4a2e-83a4-d8ab6379551f',
                                                                    '7b0a449d-5222-4974-b4c2-e6a8d3b3866c',
                                                                    '5a0d6f48-df1e-486e-9c4e-1304afc97069',
                                                                    '73372a81-037d-43eb-bd3b-5e80092f0057',
                                                                    'ee03a79b-0b96-408b-9a64-9dbc7407da31',
                                                                    '06dd48c2-ac83-4a50-a6f6-e9260c6d0788',
                                                                    '38afbd7a-cad5-4ad7-b39d-0a77f2086989', 
                                                                    '3b47e46e-d059-4e2a-a4c2-20cbf63ed055',
                                                                    'bff76789-bbf7-4c52-b6ba-9add045b1a77',
                                                                    'c7d337e4-4bb4-4747-8d75-09e6aaef8446')
                                                                    ");
    }
}
