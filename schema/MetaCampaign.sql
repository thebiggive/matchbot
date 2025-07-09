-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `MetaCampaign` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `title` varchar(255) NOT NULL,
  `currency` varchar(255) NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  `hidden` tinyint(1) NOT NULL,
  `summary` varchar(1000) DEFAULT NULL,
  `bannerURI` varchar(255) DEFAULT NULL,
  `startDate` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `endDate` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `isRegularGiving` tinyint(1) NOT NULL,
  `isEmergencyIMF` tinyint(1) NOT NULL,
  `salesforceLastPull` datetime DEFAULT NULL,
  `salesforceId` varchar(18) DEFAULT NULL,
  `total_adjustment_amountInPence` int NOT NULL,
  `total_adjustment_currency` varchar(3) NOT NULL,
  `imf_campaign_target_override_amountInPence` int NOT NULL,
  `imf_campaign_target_override_currency` varchar(3) NOT NULL,
  `match_funds_total_amountInPence` int NOT NULL,
  `match_funds_total_currency` varchar(3) NOT NULL,
  `masterCampaignStatus` varchar(255) DEFAULT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_C36155EC989D9B62` (`slug`),
  UNIQUE KEY `UNIQ_C36155ECD8961D21` (`salesforceId`),
  KEY `slug` (`slug`),
  KEY `title` (`title`),
  KEY `status` (`status`),
  KEY `hidden` (`hidden`)
)
