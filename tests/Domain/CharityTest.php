<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Charity;
use MatchBot\Tests\TestCase;

class CharityTest extends TestCase
{
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
            time: new \DateTime()
        );
    }

    public function testTBGcanClaimGiftAidStatus(): void 
    {
        $charity = TestCase::someCharity();
        $charity->updateFromSfPull(
            charityName: "Charity Name",
            stripeAccountId: "accountid",
            hmrcReferenceNumber: "hmrcref",
            giftAidOnboardingStatus: "Onboarded & Approved",
            regulator: null,
            regulatorNumber: null,
            time: new \DateTime(),
        );

        $this->assertTrue($charity->isTbgClaimingGiftAid());
    }
}
