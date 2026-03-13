<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260311173850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alter all ORM managed tables to follow ORM v3 conventions';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE Campaign CHANGE regularGivingCollectionEnd regularGivingCollectionEnd DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE lastCheck lastCheck DATETIME DEFAULT NULL, CHANGE lastRealUpdate lastRealUpdate DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE Donation CHANGE uuid uuid CHAR(36) NOT NULL, CHANGE collectedAt collectedAt DATETIME DEFAULT NULL, CHANGE refundedAt refundedAt DATETIME DEFAULT NULL, CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt DATETIME DEFAULT NULL, CHANGE preAuthorizationDate preAuthorizationDate DATETIME DEFAULT NULL, CHANGE giftAidRemovedAt giftAidRemovedAt DATETIME DEFAULT NULL, CHANGE donorUUID donorUUID CHAR(36) DEFAULT NULL, CHANGE paidOutAt paidOutAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE DonorAccount CHANGE uuid uuid CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE EmailVerificationToken CHANGE createdAt createdAt DATETIME NOT NULL');
        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE releasedAt releasedAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE startDate startDate DATETIME NOT NULL, CHANGE endDate endDate DATETIME NOT NULL');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE uuid uuid CHAR(36) NOT NULL, CHANGE personid personid CHAR(36) NOT NULL, CHANGE activeFrom activeFrom DATETIME DEFAULT NULL, CHANGE donationsCreatedUpTo donationsCreatedUpTo DATETIME DEFAULT NULL, CHANGE cancelledAt cancelledAt DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE Campaign CHANGE regularGivingCollectionEnd regularGivingCollectionEnd DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE CampaignStatistics CHANGE lastCheck lastCheck DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE lastRealUpdate lastRealUpdate DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE Donation CHANGE uuid uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE collectedAt collectedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE giftAidRemovedAt giftAidRemovedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE donorUUID donorUUID CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', CHANGE tbgGiftAidRequestQueuedAt tbgGiftAidRequestQueuedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE refundedAt refundedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE preAuthorizationDate preAuthorizationDate DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE paidOutAt paidOutAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE DonorAccount CHANGE uuid uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE EmailVerificationToken CHANGE createdAt createdAt DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE FundingWithdrawal CHANGE releasedAt releasedAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE MetaCampaign CHANGE startDate startDate DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE endDate endDate DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE RegularGivingMandate CHANGE uuid uuid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\', CHANGE activeFrom activeFrom DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE donationsCreatedUpTo donationsCreatedUpTo DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE cancelledAt cancelledAt DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE personid personid CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }
}
