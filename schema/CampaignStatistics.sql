-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `CampaignStatistics` (
  `campaign_id` int unsigned NOT NULL,
  `campaignSalesforceId` varchar(18) NOT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `amount_raised_amountInPence` int NOT NULL,
  `amount_raised_currency` varchar(3) NOT NULL,
  `match_funds_used_amountInPence` int NOT NULL,
  `match_funds_used_currency` varchar(3) NOT NULL,
  `donation_sum_amountInPence` int NOT NULL,
  `donation_sum_currency` varchar(3) NOT NULL,
  `match_funds_total_amountInPence` int NOT NULL,
  `match_funds_total_currency` varchar(3) NOT NULL,
  `match_funds_remaining_amountInPence` int NOT NULL,
  `match_funds_remaining_currency` varchar(3) NOT NULL,
  `distance_to_target_amountInPence` int NOT NULL,
  `distance_to_target_currency` varchar(3) NOT NULL,
  `lastCheck` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `lastRealUpdate` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`campaign_id`),
  UNIQUE KEY `UNIQ_7DDC8DA446D048DD` (`campaignSalesforceId`),
  KEY `amount_raised_amountInPence` (`amount_raised_amountInPence`),
  KEY `match_funds_used_amountInPence` (`match_funds_used_amountInPence`),
  CONSTRAINT `FK_7DDC8DA4F639F774` FOREIGN KEY (`campaign_id`) REFERENCES `Campaign` (`id`)
)
