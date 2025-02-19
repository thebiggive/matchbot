<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\RegularGivingMandate;

use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class CancelAsAdminTest extends TestCase
{
    public function testSuccess(): void
    {
        $mandate = $this->getTestMandate();
        $mandateUuidString = $mandate->getUuid()->toString();

        $request = self::createRequest('POST', "/v1/mandates/$mandateUuidString/cancel")
            ->withHeader('x-send-verify-hash', $this->getSalesforceAuthValue(''));

        $response = $this->getAppInstance()->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAlreadyCancelled(): void
    {
        $mandate = $this->getTestMandate();
        $mandate->cancel(
            reason: '',
            at: new \DateTimeImmutable(),
            type: MandateCancellationType::DonorRequestedCancellation
        );

        $mandateUuidString = $mandate->getUuid()->toString();

        $request = self::createRequest('POST', "/v1/mandates/$mandateUuidString/cancel")
            ->withHeader('x-send-verify-hash', $this->getSalesforceAuthValue(''));


        $response = $this->getAppInstance()->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
        // todo check body contents
    }

    private function getTestMandate(): RegularGivingMandate
    {
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('campaignId12345678')
        );

        return new RegularGivingMandate(
            donorId: PersonId::of(Uuid::uuid4()->toString()),
            donationAmount: Money::fromPoundsGBP(20),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: false,
            dayOfMonth: DayOfMonth::of(2),
        );
    }

    private function getSalesforceAuthValue(string $body): string
    {
        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        return hash_hmac('sha256', $body, $salesforceSecretKey);
    }
}
