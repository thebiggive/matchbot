<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250424095340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct wrong donor details for one donation in prod';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM DonorAccount WHERE uuid = "1f020f17-6b2f-6212-bc30-25c4279c44e3"'
        );

        if ($rows === []) {
            // presumably we're not in prod.
            return;
        }

        $dataSource = $rows[0];

        // As I don't have direct write access to the Matchbot DB I registered an account using my email address
        // with plus-addressing and putting the info about the email address we actually want to use in the plus part.
        // Extracting that data here.
        $sourceEmail = $dataSource['email'];
        \assert(is_string($sourceEmail));

        $sourceEmailLocalPart = explode('@', $sourceEmail)[0];
        $sourceEmailPostPlusPart = explode('+', $sourceEmailLocalPart)[1];

        $correctEmailAddress = str_replace('AT','@', $sourceEmailPostPlusPart);
        $firstName = $lastName = $dataSource['donorName_first'];
        \assert(is_string($firstName));
        \assert(is_string($lastName));

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE Donation set donorEmailAddress = ?, donorFirstName = ?, donorLastName = ?, salesforcePushStatus = 'pending-update'
                WHERE uuid = "60d654fb-8994-4522-9c79-dfbceee40c61" LIMIT 1
            SQL
            ,
            [$correctEmailAddress, $firstName, $lastName]
        );
    }

    public function down(Schema $schema): void
    {
        // no-un-patch.
    }
}
