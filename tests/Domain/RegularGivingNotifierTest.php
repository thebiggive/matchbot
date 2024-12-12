<?php

namespace Domain;

use MatchBot\Client\Mailer;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingNotifier;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

class RegularGivingNotifierTest extends TestCase
{
    public function testItNotifiesOfNewDonationMandate(): void
    {
        $mailerProphecy = $this->prophesize(Mailer::class);

        $clock = new MockClock('2024-12-01');
        $sut = new RegularGivingNotifier($mailerProphecy->reveal(), $clock);

        $campaign = TestCase::someCampaign(thankYouMessage: 'Thank you for setting up your regular donation to us!');
        $personId = PersonId::of(Uuid::uuid4()->toString());
        $donor = new DonorAccount(
            uuid: $personId,
            emailAddress: EmailAddress::of('donor@example.com'),
            donorName: DonorName::of('Jenny', 'Generous'),
            stripeCustomerId: StripeCustomerId::of('cus_anyid'),
        );

        /**
         * @psalm-suppress PossiblyNullArgument
         */
        $mandate = new RegularGivingMandate(
            donorId: $personId,
            amount: Money::fromPoundsGBP(64),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: true,
            dayOfMonth: DayOfMonth::of(12),
        );

        $mandate->activate($clock->now());

        // assert
        $mailerProphecy->sendEmail(
            [
                "templateKey" => "donor-mandate-confirmation",
                "recipientEmailAddress" => "donor@example.com",
                "params" => [
                    "charityName" => "Charity Name",
                    "campaignName" => "someCampaign",
                    "charityNumber" => "Reg-no",
                    "campaignThankYouMessage" => 'Thank you for setting up your regular donation to us!',
                    "signupDate" => "01/12/2024 00:00",
                    "schedule" => "Monthly on day #12",
                    "nextPaymentDate" => "12/12/2024",
                    "amount" => "£64.00",
                    "giftAidValue" => "",
                    "totalIncGiftAd" => "",
                    "totalCharged" => "£64.00",
                    "charityPostalAddress" => "",
                    "charityPhoneNumber" => "",
                    "charityEMailAddress" => "",
                    "charityWebsite" => "",
                    "firstDonation" => [
                        // mostly same keys as used on the donorDonationSuccess email
                        // @todo -- fill in properties below in implementation
            //                        'donationDatetime' => new \DateTimeImmutable('2023-01-30'),
            //                        'currencyCode' => 'GBP',
            //                        'charityName' => '[Charity Name]',
            //                        'donationAmount' => 25_000,
            //                        'giftAidAmountClaimed' => 1_000,
            //                        'totalWithGiftAid' => 26_000,
            //                        'matchedAmount' => 25_000,
            //                        'totalCharityValueAmount' => 50_000,
            //                        'transactionId' => '[PSP Transaction ID]',
            //                        'statementReference' => 'The Big Give [Charity Name]'
                    ]
                ]
            ],
        )->shouldBeCalledOnce();

        //act
        $sut->notifyNewMandateCreated($mandate, $donor, $campaign);
    }
}
