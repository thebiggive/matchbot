-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `FundingWithdrawal` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `donation_id` int unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `campaignFunding_id` int unsigned DEFAULT NULL,
  `reversedBy_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_5C8EAC125F1168E2` (`reversedBy_id`),
  KEY `IDX_5C8EAC124DC1279C` (`donation_id`),
  KEY `IDX_5C8EAC12CB9EBA34` (`campaignFunding_id`),
  CONSTRAINT `FK_5C8EAC124DC1279C` FOREIGN KEY (`donation_id`) REFERENCES `Donation` (`id`),
  CONSTRAINT `FK_5C8EAC125F1168E2` FOREIGN KEY (`reversedBy_id`) REFERENCES `FundingWithdrawal` (`id`),
  CONSTRAINT `FK_5C8EAC12CB9EBA34` FOREIGN KEY (`campaignFunding_id`) REFERENCES `CampaignFunding` (`id`)
)
