<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BG2-2987 – Zero 43 CC25 pledges that were hard deleted in Salesforce.
 */
final class Version20251029113008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zero 43 CC25 pledges that were hard deleted in Salesforce';
    }

    public function up(Schema $schema): void
    {
        $pledgeSfIds = [
            'a0AWS00000EO2LZ2A1',
            'a0AWS00000Ezf7q2AB',
            'a0AWS00000F5Am52AF',
            'a0AWS00000F5sok2AB',
            'a0AWS00000F8IDd2AN',
            'a0AWS00000F9IMr2AN',
            'a0AWS00000FemDN2AZ',
            'a0AWS00000Fgrwv2AB',
            'a0AWS00000FIfiP2AT',
            'a0AWS00000FJMkA2AX',
            'a0AWS00000FKRoG2AX',
            'a0AWS00000FKT3d2AH',
            'a0AWS00000FlDlx2AF',
            'a0AWS00000FLwpx2AD',
            'a0AWS00000Fn0iz2AB',
            'a0AWS00000FPcuH2AT',
            'a0AWS00000FPH9t2AH',
            'a0AWS00000FPNdX2AX',
            'a0AWS00000FPPMS2A5',
            'a0AWS00000FPXrN2AX',
            'a0AWS00000FPXwF2AX',
            'a0AWS00000FPYGM2A5',
            'a0AWS00000FPYXJ2A5',
            'a0AWS00000FPZeg2AH',
            'a0AWS00000FPZht2AH',
            'a0AWS00000FQU0D2AX',
            'a0AWS00000FSbZO2A1',
            'a0AWS00000FSsRR2A1',
            'a0AWS00000FV3Yr2AL',
            'a0AWS00000FWWk52AH',
            'a0AWS00000GA4Yz2AL',
            'a0AWS00000GaB0Z2AV',
            'a0AWS00000GCtCP2A1',
            'a0AWS00000GCv1J2AT',
            'a0AWS00000GDNxc2AH',
            'a0AWS00000Gf3AP2AZ',
            'a0AWS00000GFrfV2AT',
            'a0AWS00000GFTID2A5',
            'a0AWS00000GFu8j2AD',
            'a0AWS00000GIyHR2A1',
            'a0AWS00000GJLqj2AH',
            'a0AWS00000GQXPj2AP',
            'a0AWS00000GY09F2AT',
        ];

        $this->addSql(<<<EOT
            UPDATE CampaignFunding SET amount = 0, amountAvailable = 0, updatedAt = NOW()
            WHERE fund_id IN (
                SELECT id FROM Fund WHERE salesforceId IN (:pledgeSfIds) AND (fundType = 'pledge' OR fundType = 'topupPledge')
            );
        EOT,
            ['pledgeSfIds' => $pledgeSfIds],
            ['pledgeSfIds' => ArrayParameterType::STRING], // https://stackoverflow.com/a/36710894/2803757
        );
    }

    public function down(Schema $schema): void
    {
        // No un-patch
    }
}
