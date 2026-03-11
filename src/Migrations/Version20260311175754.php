<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260311175754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Continue updating columns to work with Doctrine ORM 3';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE lastCheck lastCheck DATE DEFAULT NULL, CHANGE lastRealUpdate lastRealUpdate DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE refundedAt refundedAt DATE DEFAULT NULL, CHANGE preAuthorizationDate preAuthorizationDate DATE DEFAULT NULL, CHANGE giftAidRemovedAt giftAidRemovedAt DATE DEFAULT NULL, CHANGE paidOutAt paidOutAt DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE startDate startDate DATE NOT NULL, CHANGE endDate endDate DATE NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE activeFrom activeFrom DATE DEFAULT NULL, CHANGE donationsCreatedUpTo donationsCreatedUpTo DATE DEFAULT NULL, CHANGE cancelledAt cancelledAt DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE lastCheck lastCheck DATETIME DEFAULT NULL, CHANGE lastRealUpdate lastRealUpdate DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE giftAidRemovedAt giftAidRemovedAt DATETIME DEFAULT NULL, CHANGE refundedAt refundedAt DATETIME DEFAULT NULL, CHANGE preAuthorizationDate preAuthorizationDate DATETIME DEFAULT NULL, CHANGE paidOutAt paidOutAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE startDate startDate DATETIME NOT NULL, CHANGE endDate endDate DATETIME NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE activeFrom activeFrom DATETIME DEFAULT NULL, CHANGE donationsCreatedUpTo donationsCreatedUpTo DATETIME DEFAULT NULL, CHANGE cancelledAt cancelledAt DATETIME DEFAULT NULL');
    }
}
