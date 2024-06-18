<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Messenger\Handler\DonationStateUpdatedHandler;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class UpdateDonationPushedToSFTest extends IntegrationTest
{
    public function testUpdateDonationPushedToSF(): void
    {
        //arrange
        $response = $this->createDonation();
        $logger = new Logger();
        $this->getContainer()->set(LoggerInterface::class, $logger);
        $donationRepo = $this->getContainer()->get(DonationRepository::class);
        $donationRepo->setLogger($logger);
        /** @var array{'donation': array} $decoded */
        $decoded = \json_decode((string)$response->getBody(), associative: true);
        /** @var string $donationUUID */
        $donationUUID = $decoded['donation']['donationId'];
        $donation = $donationRepo->findOneBy(['uuid' => $donationUUID]);
        assert($donation !== null);
        $message = DonationStateUpdated::fromDonation($donation, isNew: true);

        $em = $this->getService(EntityManagerInterface::class);
        $em->clear();
        //act ?
        $sut = new DonationStateUpdatedHandler($donationRepo, $logger);

        $sut->__invoke($message);

        // assert

        $em->clear();
        /** @var Donation $donation */
        $donation = $donationRepo->findOneBy(['uuid' => $donationUUID]);
        $donationId = $donation->getId();
        $donationSfId = $donation->getSalesforceId();

        $this->assertSame(
            "info: pushing one donation $donationUUID
info: about to push now
info: isNew: true
info: in write proxy push function
info: Pushing MatchBot\Domain\Donation $donationId...
info: ... Created MatchBot\Domain\Donation $donationId : SF ID $donationSfId
info: push done",
            trim($logger->getLogs())
        );

        $this->assertSame(
            Donation::PUSH_STATUS_COMPLETE,
            $donation->getSalesforcePushStatus()
        );
    }
}

// @codingStandardsIgnoreStart
class Logger implements LoggerInterface
{
    // @codingStandardsIgnoreEnd
    use LoggerTrait;

    private string $logs = '';

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logs .= "$level: $message\n";
    }

    public function getLogs(): string
    {
        return $this->logs;
    }
}
