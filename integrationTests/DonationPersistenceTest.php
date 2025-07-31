<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class DonationPersistenceTest extends IntegrationTest
{
    public function testItSavesADonationToDB(): void
    {
        // arrange
        $em = $this->getService(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $donation = TestCase::someDonation();
        $em->persist($donation->getCampaign());
        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 10:00:00'));

        // act
        $em->persist($donation);
        $em->flush();

        // assert
        $donationId = $donation->getId();
        $donationRow = $connection->fetchAssociative("SELECT * from Donation where Donation.id = ?", [$donationId]);

        $this->assertNotFalse($donationRow);
        $this->assertRowsSimilar($this->donationRow(), $donationRow);
    }

    /**
     * Asserts that two DB rows are similar, ignoring things out of our contril like IDs and dates.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertRowsSimilar(array $expected, array $actual, string $message = ''): void
    {
        $ignoredColumns = ['id' => 0, 'uuid' => 0, 'updatedAt' => 0, 'createdAt' => 0, 'campaign_id' => 0];

        $this->assertSame($ignoredColumns + $expected, $ignoredColumns + $actual, $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function donationRow(): array
    {
        return [
            'campaign_id' => null,
            'uuid' => Uuid::uuid4(),
            'transactionId' => null,
            'amount' => '1.00',
            'donationStatus' => DonationStatus::Refunded->value,
            'charityComms' => null,
            'giftAid' => 0,
            'tbgComms' => null,
            'donorCountryCode' => null,
            'donorEmailAddress' => null,
            'donorFirstName' => null,
            'donorLastName' => null,
            'donorPostalAddress' => null,
            'salesforceLastPush' => null,
            'salesforcePushStatus' => 'pending-create',
            'salesforceId' => null,
            'tipAmount' => '0.00',
            'psp' => 'stripe',
            'donorHomeAddressLine1' => null,
            'donorHomePostcode' => null,
            'tipGiftAid' => null,
            'chargeId' => null,
            'championComms' => null,
            'charityFee' => '0.22',
            'charityFeeVat' => '0.04',
            'originalPspFee' => '0.00',
            'currencyCode' => 'GBP',
            'collectedAt' => null,
            'refundedAt' => '2023-06-22 10:00:00',
            'tbgShouldProcessGiftAid' => 1,
            'tbgGiftAidRequestQueuedAt' => null,
            'tbgGiftAidRequestFailedAt' => null,
            'transferId' => null,
            'tbgGiftAidRequestConfirmedCompleteAt' => null,
            'tbgGiftAidRequestCorrelationId' => null,
            'tbgGiftAidResponseDetail' => null,
            'pspCustomerId' => null,
            'paymentMethodType' => 'card',
            'totalPaidByDonor' => null,
            'preAuthorizationDate' => null,
            'mandate_id' => null,
            'mandateSequenceNumber' => null,
            'tipRefundAmount' => null,
            'giftAidRemovedAt' => null,
            'donorUUID' => '00000000-0000-0000-0000-000000000000',
            'stripePayoutId' => null,
            'paidOutAt' => null,
            'payoutSuccessful' => 0,
            'paymentCard_brand' => null,
            'paymentCard_country' => null,
            'updatedAt' => '1970-01-01',
            'createdAt' => '1970-01-01'
        ];
    }
}
