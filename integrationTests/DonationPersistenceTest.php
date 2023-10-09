<?php

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\IntegrationTests\IntegrationTest;
use Ramsey\Uuid\Uuid;

class DonationPersistenceTest extends IntegrationTest
{
    public function testItSavesADonationToDB(): void
    {
        // arrange
        $em = $this->getService(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $donation = $this->makeDonationObject();
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


    public function testItLoadsADonationToDB(): void
    {
        $connection = $this->getService(EntityManagerInterface::class)->getConnection();
        $donationRepo = $this->getService(DonationRepository::class);
        $donationRow = $this->donationRow();
        $connection->insert('Donation', $donationRow);

        $donation = $donationRepo->findOneBy(['uuid' => $donationRow['uuid']]);

        $this->assertNotNull($donation);
        $this->assertEquals(DonationStatus::Refunded, $donation->getDonationStatus());
    }

    /**
     * Asserts that two DB rows are similar, ignoring things out of our contril like IDs and dates.
     */
    private function assertRowsSimilar(array $expected, array $actual, string $message = ''): void
    {
        $ignoredColumns = ['id' => 0, 'uuid' => 0, 'updatedAt' => 0, 'createdAt' => 0];

        $this->assertSame($ignoredColumns + $expected, $ignoredColumns + $actual , $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function donationRow(): array
    {
        return [
            'campaign_id' => NULL,
            'uuid' => Uuid::uuid4(),
            'transactionId' => NULL,
            'amount' => '1.00',
            'donationStatus' => 'Refunded',
            'charityComms' => NULL,
            'giftAid' => NULL,
            'tbgComms' => NULL,
            'donorCountryCode' => NULL,
            'donorEmailAddress' => NULL,
            'donorFirstName' => NULL,
            'donorLastName' => NULL,
            'donorPostalAddress' => NULL,
            'salesforceLastPush' => NULL,
            'salesforcePushStatus' => 'not-sent',
            'salesforceId' => NULL,
            'tipAmount' => '0.00',
            'psp' => 'stripe',
            'donorHomeAddressLine1' => NULL,
            'donorHomePostcode' => NULL,
            'tipGiftAid' => NULL,
            'chargeId' => NULL,
            'championComms' => NULL,
            'charityFee' => '0.00',
            'charityFeeVat' => '0.00',
            'originalPspFee' => '0.00',
            'currencyCode' => 'GBP',
            'feeCoverAmount' => '0.00',
            'collectedAt' => NULL,
            'refundedAt' => '2023-06-22 10:00:00',
            'tbgShouldProcessGiftAid' => NULL,
            'tbgGiftAidRequestQueuedAt' => NULL,
            'tbgGiftAidRequestFailedAt' => NULL,
            'transferId' => NULL,
            'tbgGiftAidRequestConfirmedCompleteAt' => NULL,
            'tbgGiftAidRequestCorrelationId' => NULL,
            'tbgGiftAidResponseDetail' => NULL,
            'pspCustomerId' => NULL,
            'paymentMethodType' => 'card',

            'updatedAt' => '1970-01-01',
            'createdAt' => '1970-01-01'
        ];
    }

    /**
     * @return Donation
     */
    public function makeDonationObject(): Donation
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setUuid(Uuid::uuid4());
        $donation->setPsp('stripe');
        $donation->setDonationStatus(DonationStatus::Collected);

        return $donation;
    }
}