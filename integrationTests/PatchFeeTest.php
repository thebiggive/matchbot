<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Commands\PatchHistoricNonDefaultFeeDonations;
use Stripe\Charge;
use Stripe\Service\ChargeService;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Symfony\Component\Console\Tester\CommandTester;

class PatchFeeTest extends IntegrationTest
{
    public function testItCorrectsFeeRecordOnOldDonation(): void
    {
        // arrange
        $chargeId = 'chg' . $this->randomString();
        $correctedCharityFee = '23.00';
        $correctedCharityVatFee = '25.00';

        $container = $this->getContainer();

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $chargeServiceProphecy = $this->prophesize(ChargeService::class);

        $chargeServiceProphecy->retrieve($chargeId)->willReturn(
            Charge::constructFrom([
                'metadata' => [
                    'stripeFeeRechargeNet' => $correctedCharityFee,
                    'stripeFeeRechargeVat' => $correctedCharityVatFee,
                ],
            ])
        );

        $stripeClientProphecy->charges = $chargeServiceProphecy->reveal();

        /** @psalm-suppress MixedArrayAccess */
        $donationUUID = json_decode(
            (string)$this->createDonation(100)->getBody(),
            true,
            JSON_THROW_ON_ERROR,
            JSON_THROW_ON_ERROR
        )['donation']['donationId'];
        \assert(is_string($donationUUID));

        $donorFirstName = self::class;

        $rowsAffected = $this->db()->executeStatement(
            "UPDATE Donation set Donation.charityFee=1, Donation.charityFeeVat = 1, donationStatus = 'Paid', chargeId = '$chargeId', donorFirstName='{$donorFirstName}'
                WHERE uuid = ?",
            [$donationUUID]
        );
        \assert($rowsAffected === 1);

        $container->set(StripeClient::class, $stripeClientProphecy->reveal());
        $commandTester = new CommandTester($this->getService(PatchHistoricNonDefaultFeeDonations::class));

        // act
        $commandTester->execute(input: []);

        // assert
        $display = $commandTester->getDisplay();

        $updatedFees = $this->db()->fetchAssociative(
            'SELECT charityFee, charityFeeVat FROM Donation WHERE uuid = ?', [$donationUUID]
        );

        $this->assertStringContainsString(
            "Donation data updated:  {\"donationUuid\":\"$donationUUID\",\"charityFee\":\"$correctedCharityFee\",\"charityFeeVat\":\"$correctedCharityVatFee\"}",
            $display
        );

        $this->assertSame(['charityFee' => $correctedCharityFee, 'charityFeeVat' => $correctedCharityVatFee], $updatedFees);
    }

    public function tearDown(): void
    {
        // ideally, we'd delete them, but foreign key constraints make that harder. Seting status to pending means the
        // donation won't interfere with the next test run.
        $donorFirstName = self::class;
        $this->db()->executeStatement(
            "UPDATE Donation set Donation.donationStatus = 'Pending' where donorFirstName = '$donorFirstName'"
        );
    }
}
