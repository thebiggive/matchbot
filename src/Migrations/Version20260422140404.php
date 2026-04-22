<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422140404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BG2-3110: Resubmit donations for gift aid';
    }

    public function up(Schema $schema): void
    {
        // change below means the donations should be found by
        // \MatchBot\Domain\DoctrineDonationRepository::findReadyToClaimGiftAid next time it's called.

        $this->addSql(<<<EOT
            UPDATE Donation
            SET tbgGiftAidRequestQueuedAt = NULL
            WHERE
                donationStatus in ('Paid', 'Collected') AND 
                giftAid = 1 AND 
                tbgShouldProcessGiftAid = 1 AND
                salesforceId in (
                                'a06WS00000HFZWmYAP',
                                'a06WS00000HFtKNYA1',
                                'a06WS00000HG6nrYAD',
                                'a06WS00000HGOz7YAH',
                                'a06WS00000HGQUfYAP',
                                'a06WS00000HGZ51YAH',
                                'a06WS00000HGZXwYAP',
                                'a06WS00000HGej0YAD',
                                'a06WS00000HGuvhYAD',
                                'a06WS00000HH4iBYAT',
                                'a06WS00000HHFIhYAP',
                                'a06WS00000HHVf9YAH',
                                'a06WS00000HHWcoYAH',
                                'a06WS00000HHe8vYAD',
                                'a06WS00000HImUHYA1',
                                'a06WS00000HInjdYAD',
                                'a06WS00000HJ8EbYAL',
                                'a06WS00000HJUn1YAH',
                                'a06WS00000HJiTOYA1',
                                'a06WS00000HKmrZYAT',
                                'a06WS00000HMMcsYAH',
                                'a06WS00000HMmvaYAD',
                                'a06WS00000HMs9hYAD',
                                'a06WS00000HMwOaYAL',
                                'a06WS00000HMxfZYAT',
                                'a06WS00000HN00qYAD',
                                'a06WS00000HOKezYAH',
                                'a06WS00000HOf1pYAD',
                                'a06WS00000HPBeSYAX',
                                'a06WS00000HPYb4YAH',
                                'a06WS00000HPfI4YAL',
                                'a06WS00000HPg5VYAT',
                                'a06WS00000HPlmlYAD',
                                'a06WS00000HPyTpYAL',
                                'a06WS00000HQDxXYAX',
                                'a06WS00000HTF0JYAX',
                                'a06WS00000HTM9pYAH',
                                'a06WS00000HYlXUYA1',
                                'a06WS00000HZ0BXYA1',
                                'a06WS00000HZDWvYAP',
                                'a06WS00000HZXsHYAX',
                                'a06WS00000HZYLBYA5',
                                'a06WS00000HZaejYAD',
                                'a06WS00000Hat4EYAR',
                                'a06WS00000HbLobYAF',
                                'a06WS00000HbaXQYAZ',
                                'a06WS00000Hbk5LYAR',
                                'a06WS00000HbwPsYAJ',
                                'a06WS00000HcLg0YAF',
                                'a06WS00000HcOFhYAN',
                                'a06WS00000HcuvZYAR',
                                'a06WS00000Hd48ZYAR',
                                'a06WS00000Hd8aHYAR',
                                'a06WS00000HdHMDYA3',
                                'a06WS00000He6eTYAR',
                                'a06WS00000HeAtNYAV',
                                'a06WS00000HeFD5YAN',
                                'a06WS00000HeG2jYAF',
                                'a06WS00000HeHA4YAN',
                                'a06WS00000HeHtFYAV',
                                'a06WS00000HeMxiYAF',
                                'a06WS00000HeS8fYAF',
                                'a06WS00000HeULnYAN',
                                'a06WS00000Hez7rYAB',
                                'a06WS00000Hf0QTYAZ',
                                'a06WS00000HfMUDYA3',
                                'a06WS00000HfNepYAF'
                                         )
            LIMIT 67
          EOT
        );
    }

    public function down(Schema $schema): void
    {
        throw new \Exception('no going back');
    }
}
