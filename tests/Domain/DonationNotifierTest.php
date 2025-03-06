<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\SendEmailCommand;
use MatchBot\Tests\TestCase;

class DonationNotifierTest extends TestCase
{
    public function testItGeneratesAnEmailCommandForADonation(): void
    {
        $donation = $this->makeCollectedDonation(
            amount: '10',
            emailAddress: EmailAddress::of('fred@example.com'),
            tipAmount: '2',
        );

        $emailCommand = DonationNotifier::emailCommandForCollectedDonation($donation);

        $this->assertEquals(
            [
                'donor-donation-success',
                'fred@example.com',
                [
                    // required params taken from mailer repo:
                    // https://github.com/thebiggive/mailer/blob/ca2c70f10720a66ff8fb041d3af430a07f49d625/app/settings.php#L28
                    'campaignName' => 'someCampaign',
                    'campaignThankYouMessage' => "Thank you for your donation.",
                    'charityName' => 'Charity Name',
                    'currencyCode' => 'GBP',
                    'donationAmount' => 10.0,
                    'donorFirstName' => 'Genny',
                    'donorLastName' => 'Jenerous',
                    'paymentMethodType' => 'card',
                    'giftAidAmountClaimed' => 2.50,
                    'matchedAmount' => 6.0,
                    'tipAmount' => 2.0,
                    'totalChargedAmount' => 12.00,
                    'totalCharityValueAmount' => 18.50, // amount + matched amount + gift aid
                    'transactionId' => 'some-transaction-id',
                    'charityRegistrationAuthority' => 'Charity Commission for England and Wales'
                ]
            ],
            [$emailCommand->templateKey, $emailCommand->emailAddress->email, $emailCommand->params]
        );
    }

    /**
     * @param numeric-string $amount
     * @param numeric-string $tipAmount
     */
    private function makeCollectedDonation(string $amount, EmailAddress $emailAddress, string $tipAmount): \MatchBot\Domain\Donation
    {
        $campaign = self::someCampaign(
            thankYouMessage: "Thank you for your donation.",
        );

        $donation = self::someDonation(
            amount: $amount,
            campaign: $campaign,
            emailAddress: $emailAddress,
            donorName: DonorName::of('Genny', 'Jenerous'),
            giftAid: true,
            tipAmount: $tipAmount,
        );

        $donation->setTransactionId('some-transaction-id');

        $donation->collectFromStripeCharge(
            chargeId: 'charge-id',
            totalPaidFractional: (int)((bcadd($amount, $tipAmount)) * 100),
            transferId: 'transfer-id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '100',
            chargeCreationTimestamp: 0
        );

        $this->addMatchFunds($donation, '1', FundType::Pledge);
        $this->addMatchFunds($donation, '2', FundType::ChampionFund);
        $this->addMatchFunds($donation, '3', FundType::TopupPledge);

        return $donation;
    }


    /**
     * @param numeric-string $amount
     */
    public function addMatchFunds(Donation $donation, string $amount, FundType $fundType): void
    {
        $fundingWithdrawal = new FundingWithdrawal(
            new CampaignFunding(
                new Fund('GBP', 'some-fund', null, $fundType),
                amount: '100',
                amountAvailable: '1',
                allocationOrder: 1
            )
        );

        $fundingWithdrawal->setAmount($amount);

        $donation->addFundingWithdrawal(
            $fundingWithdrawal
        );
    }
}
