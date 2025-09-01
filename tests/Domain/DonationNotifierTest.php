<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\EmailVerificationToken;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PostalAddress;
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

        $emailCommand = DonationNotifier::emailMessageForCollectedDonation(
            $donation,
            'https://donate.example.org',
            new EmailVerificationToken(
                '123456',
                'not-relavent@example.com',
                new \DateTimeImmutable()
            )
        );

        $this->assertEquals(
            [
                'donor-donation-success',
                'fred@example.com',
                [
                    // includes required params taken from mailer repo:
                    // https://github.com/thebiggive/mailer/blob/ca2c70f10720a66ff8fb041d3af430a07f49d625/app/settings.php#L28
                    // and other params used in template.

                    'campaignName' => 'someCampaign',
                    'campaignThankYouMessage' => "Thank you for your donation.",
                    'charityName' => 'Charity Name',
                    'charityNumber' => 'Reg-no',
                    'charityIsExempt' => false,
                    'charityRegistrationAuthority' => 'Charity Commission for England and Wales',
                    'createAccountUri' => 'https://donate.example.org/register?c=123456&u=00000000-0000-0000-0000-000000000000',
                    'currencyCode' => 'GBP',

                    'donationAmount' => 10.0,
                    'donationDatetime' => '2025-03-06T18:46:39+00:00',
                    'donorFirstName' => 'Genny',
                    'donorLastName' => 'Jenerous',
                    'giftAidAmountClaimed' => 2.50,

                    'matchedAmount' => 6.0,
                    'paymentMethodType' => 'card',
                    'statementReference' => 'Big Give Charity Name',
                    'tipAmount' => 2.0,
                    'totalChargedAmount' => 12.00,

                    'totalCharityValueAmount' => 18.50, // amount + matched amount + gift aid
                    'transactionId' => 'some-transaction-id',
                    'charityLogoUri' => 'https://some-logo-host/charityname/logo.png',
                    'charityWebsite' => 'https://charityname.com',

                    'charityPhoneNumber' => '0191 498 0000',
                    'charityEmailAddress' => 'charity@charitiesareus.com',
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
        $charity = self::someCharity(
            phoneNumber: '0191 498 0000',
            emailAddress: EmailAddress::of('charity@charitiesareus.com'),
        );

        $campaign = self::someCampaign(
            charity: $charity,
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
            totalPaidFractional: (int)((float)(bcadd($amount, $tipAmount)) * 100.0),
            transferId: 'transfer-id',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '100',
            chargeCreationTimestamp: (new \DateTimeImmutable('2025-03-06T18:46:39+00:00'))->getTimestamp()
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
                new Fund('GBP', 'some-fund', null, null, $fundType),
                amount: '100',
                amountAvailable: '1',
            ),
            $donation,
            $amount
        );

        $donation->addFundingWithdrawal(
            $fundingWithdrawal
        );
    }
}
