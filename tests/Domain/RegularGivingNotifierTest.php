<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Collections\Collection;
use MatchBot\Application\Assertion;
use MatchBot\Client\Mailer;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Fund;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingNotifier;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

class RegularGivingNotifierTest extends TestCase
{
    /** @var ObjectProphecy<Mailer> */
    private ObjectProphecy $mailerProphecy;

    /** @var PersonId  */
    private PersonId $personId;

    public function testItNotifiesOfNewDonationMandate(): void
    {
        $donor = $this->givenADonor();
        list($campaign, $mandate, $firstDonation, $clock) = $this->andGivenAnActivatedMandate($this->personId, $donor);

        $this->thenThisRequestShouldBeSentToMatchbot(EmailMessage::donorMandateConfirmation(
            EmailAddress::of("donor@example.com"),
            [
                "donorName" => "Jenny Generous",
                "charityName" => "Charity Name",
                "campaignName" => "someCampaign",
                "charityNumber" => "Reg-no",
                "campaignThankYouMessage" => 'Thank you for setting up your regular donation to us!',
                "signupDate" => "01/12/2024 00:00",
                "schedule" => "Monthly on day #12",
                "nextPaymentDate" => "12/12/2024",
                "amount" => "£64.00",
                "giftAidValue" => "£16.00",
                "totalIncGiftAid" => "£80.00",
                "totalCharged" => "£64.00",
                "firstDonation" => [
                    // mostly same keys as used on the donorDonationSuccess email currently sent via Salesforce.
                    'currencyCode' => 'GBP',
                    'donationAmount' => 64,
                    'donationDatetime' => '2025-01-01T09:00:00+00:00',
                    'charityName' => 'Charity Name',
                    'matchedAmount' => 64,
                    'transactionId' => '[PSP Transaction ID]',
                    'statementReference' => 'Big Give Charity Name',
                    'giftAidAmountClaimed' => 16.00,
                    'totalCharityValueAmount' => 144.00,
                ]
            ]
        ));

        $this->whenWeNotifyThemThatTheMandateWasCreated($clock, $mandate, $donor, $campaign, $firstDonation);
    }

    private function markDonationCollected(Donation $firstDonation, \DateTimeImmutable $collectionDate): void
    {
        $firstDonation->collectFromStripeCharge(
            'chargeID',
            99,
            'transfer-id',
            CardBrand::amex,
            Country::fromAlpha2('GB'),
            '99',
            $collectionDate->getTimestamp(),
        );
    }

    /**
     * Using reflection to add funding withdrawals to donation. In prod code we rely on the ORM to link
     * Funding withdrawals to donations.
     *
     * @param numeric-string $amount
     */
    private function addFundingWithdrawal(Donation $donation, string $amount): void
    {
        $withdrawal = new FundingWithdrawal(
            new CampaignFunding(
                new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
                '100',
                '100',
            ),
            $donation,
            $amount,
        );

        $reflectionClass = new \ReflectionClass($donation);
        $property = $reflectionClass->getProperty('fundingWithdrawals');

        /** @var Collection<int, FundingWithdrawal> $fundingWithdrawals */
        $fundingWithdrawals = $property->getValue($donation);
        $fundingWithdrawals->add($withdrawal);
    }

    private function givenADonor(): DonorAccount
    {
        $donor = new DonorAccount(
            uuid: $this->personId,
            emailAddress: EmailAddress::of('donor@example.com'),
            donorName: DonorName::of('Jenny', 'Generous'),
            stripeCustomerId: StripeCustomerId::of('cus_anyid'),
        );
        $donor->setHomeAddressLine1('pretty how town');
        return $donor;
    }

    /**
     * @return list{Campaign, RegularGivingMandate, Donation, ClockInterface}
     */
    private function andGivenAnActivatedMandate(PersonId $personId, DonorAccount $donor): array
    {
        $campaign = TestCase::someCampaign(thankYouMessage: 'Thank you for setting up your regular donation to us!');

        $mandate = new RegularGivingMandate(
            donorId: $personId,
            donationAmount: Money::fromPoundsGBP(64),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: true,
            dayOfMonth: DayOfMonth::of(12),
        );

        $firstDonation = new Donation(
            amount: '64',
            currencyCode: 'GBP',
            paymentMethodType: PaymentMethodType::Card,
            campaign: $campaign,
            charityComms: false,
            championComms: false,
            pspCustomerId: $donor->stripeCustomerId->stripeCustomerId,
            optInTbgEmail: false,
            donorName: $donor->donorName,
            emailAddress: $donor->emailAddress,
            countryCode: $donor->getBillingCountryCode(),
            tipAmount: '0',
            mandate: $mandate,
            mandateSequenceNumber: DonationSequenceNumber::of(1),
            giftAid: true,
            tipGiftAid: null,
            homeAddress: null,
            homePostcode: null,
            billingPostcode: null,
            donorId: $donor->id(),
        );
        $firstDonation->setTransactionId('[PSP Transaction ID]');
        $this->addFundingWithdrawal($firstDonation, '64');

        $this->markDonationCollected($firstDonation, new \DateTimeImmutable('2025-01-01 09:00:00'));

        $clock = new MockClock('2024-12-01');
        $mandate->activate($clock->now());
        return [$campaign, $mandate, $firstDonation, $clock];
    }

    private function thenThisRequestShouldBeSentToMatchbot(EmailMessage $sendEmailCommand): void
    {
        $this->mailerProphecy->send(Argument::any())
            ->shouldBeCalledOnce()
            ->will(fn(array $args) => TestCase::assertEqualsCanonicalizing($args[0], $sendEmailCommand));
    }

    private function whenWeNotifyThemThatTheMandateWasCreated(
        ClockInterface $clock,
        RegularGivingMandate $mandate,
        DonorAccount $donor,
        Campaign $campaign,
        Donation $firstDonation
    ): void {
        $sut = new RegularGivingNotifier($this->mailerProphecy->reveal(), $clock);

        //act
        $sut->notifyNewMandateCreated($mandate, $donor, $campaign, $firstDonation);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->mailerProphecy = $this->prophesize(Mailer::class);
        $this->personId = self::randomPersonId();
    }
}
