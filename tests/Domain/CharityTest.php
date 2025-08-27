<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Charity;
use MatchBot\Domain\PostalAddress;
use MatchBot\Tests\TestCase;
use PHPUnit\Util\Test;

class CharityTest extends TestCase
{
    public function testTbGApprovedToClaimGiftAidWhenRefNumberAndRightStatusSet(): void
    {
        $charity = TestCase::someCharity();

        $charity->updateFromSfPull(
            charityName: 'name doesnt matter',
            websiteUri: null,
            logoUri: null,
            stripeAccountId: null,
            hmrcReferenceNumber: 'not-empty',
            giftAidOnboardingStatus: Charity::GIFT_AID_APPROVED_STATUS,
            regulator: null,
            regulatorNumber: null,
            rawData: [],
            time: new \DateTime(),
            phoneNumber: null,
            emailAddress: null,
        );

        $this->assertTrue($charity->getTbgApprovedToClaimGiftAid());
    }
    public function testWeAreNotApprovedToClaimGAWithoutReferenceNumber(): void
    {
        $charity = TestCase::someCharity();

        $charity->updateFromSfPull(
            charityName: 'name doesnt matter', // empty
            websiteUri: null,
            logoUri: null,
            stripeAccountId: null,
            hmrcReferenceNumber: '',
            giftAidOnboardingStatus: Charity::GIFT_AID_APPROVED_STATUS,
            regulator: null,
            regulatorNumber: null,
            rawData: [],
            time: new \DateTime(),
            phoneNumber: null,
            emailAddress: null
        );

        $this->assertFalse($charity->getTbgApprovedToClaimGiftAid());
    }

    public function testWeAreNotApprovedToClaimGAWithoutApprovedStatus(): void
    {
        $charity = TestCase::someCharity();

        $charity->updateFromSfPull(
            charityName: 'name doesnt matter',
            websiteUri: null,
            logoUri: null,
            stripeAccountId: null,
            hmrcReferenceNumber: 'not-empty',
            giftAidOnboardingStatus: 'Onboarded',
            regulator: null,
            regulatorNumber: null,
            rawData: [],
            time: new \DateTime(),
            phoneNumber: null,
            emailAddress: null
        );

        $this->assertFalse($charity->getTbgApprovedToClaimGiftAid());
    }

    public function testInvalidRegularIsDenied(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Regulator N/A not known');

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator('N/A');
    }

    public function testBlankRegularIsDenied(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Regulator  not known');

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator('');
    }

    public function testNullRegulatorIsAllowed(): void
    {
        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setRegulator(null);

        $this->assertNull($charity->getRegulator());
    }

    public function testItThrowsGivenBadOnboardingStatus(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new Charity(
            salesforceId: 'sfID',
            charityName: "Charity Name",
            stripeAccountId: "accountid",
            hmrcReferenceNumber: "hmrcref",
            giftAidOnboardingStatus: "NOT_A_POSSIBLE_STATUS",
            regulator: null,
            regulatorNumber: null,
            time: new \DateTime(),
            rawData: [],
            websiteUri: null,
            logoUri: null,
            phoneNumber: null,
            emailAddress: null
        );
    }

     /**
     * @dataProvider pullFromSFProvider
     */
    public function testTBGcanClaimGiftAidStatus(
        string $hmrcReference,
        string $giftaidOnboardingStatus,
        bool $expected,
    ): void {
        $charity = TestCase::someCharity();
        $charity->updateFromSfPull(
            charityName: "Charity Name",
            websiteUri: null,
            logoUri: null,
            stripeAccountId: "accountid",
            hmrcReferenceNumber: $hmrcReference,
            giftAidOnboardingStatus: $giftaidOnboardingStatus,
            regulator: null,
            regulatorNumber: null,
            rawData: [],
            time: new \DateTime(),
            phoneNumber: null,
            emailAddress: null,
        );

        $this->assertSame($expected, $charity->isTbgClaimingGiftAid());
    }

    public function testItGeneratesStatementDescriptor(): void
    {
        $charity = TestCase::someCharity(
            name: 'Name with /€€€€€€€€€€€€€/ this is a long name with special and multibyte chars'
        );
        $this->assertSame('Big Give Name with  th', $charity->getStatementDescriptor());
    }


    /**
     * @return array<array{0:string,1:string,2:bool}>
     */
    public function pullFromSFProvider(): array
    {
        return [
            ["hmrcref", "Onboarded & Approved", true],
            ["", "Onboarded & Approved", false],
            ["hmrcref", "Invited to Onboard", false],
            ["", "Invited to Onboard", false],
        ];
    }
}
