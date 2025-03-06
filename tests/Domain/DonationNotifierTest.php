<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\SendEmailCommand;
use MatchBot\Tests\TestCase;

class DonationNotifierTest extends TestCase {
    public function testItGeneratesAnEmailCommandForADonation(): void
    {
        $donation = $this->makeCollectedDonation('10', EmailAddress::of('fred@example.com'));

        $emailCommand = DonationNotifier::emailCommandForCollectedDonation($donation);

        $this->assertEquals(
            [
                'donor-donation-success',
                'fred@example.com',
                [
                    // required params taken from mailer repo:
                    // https://github.com/thebiggive/mailer/blob/ca2c70f10720a66ff8fb041d3af430a07f49d625/app/settings.php#L28
//                    'campaignName',
//                    'campaignThankYouMessage',
//                    'charityName',
//                    'currencyCode',
//                    'donationAmount',
//                    'donorFirstName',
//                    'paymentMethodType',
//                    'donorLastName',
//                    'giftAidAmountClaimed',
//                    'matchedAmount',
//                    'tipAmount',
//                    'totalChargedAmount',
//                    'totalCharityValueAmount',
//                    'transactionId',
                ]
            ],
            [$emailCommand->templateKey, $emailCommand->emailAddress->email, $emailCommand->params]
        );
    }

    /**
     * @param numeric-string $amount
     */
    private function makeCollectedDonation(string $amount, EmailAddress $emailAddress): \MatchBot\Domain\Donation
    {
        $donation = self::someDonation(amount: $amount, emailAddress: $emailAddress);
        $donation->collectFromStripeCharge(
            chargeId: 'charge-id',
            totalPaidFractional: (int)($amount * 100),
            transferId: 'transfer-id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '100',
            chargeCreationTimestamp: 0
        );
        return $donation;
    }
}