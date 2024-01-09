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
            stripeAccountId: "accountid",
            hmrcReferenceNumber: $hmrcReference,
            giftAidOnboardingStatus: $giftaidOnboardingStatus,
            regulator: null,
            regulatorNumber: null,
            time: new \DateTime(),
        );

        $this->assertSame($expected, $charity->isTbgClaimingGiftAid());
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
