-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `CampaignFunding` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `fund_id` int unsigned DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `amountAvailable` decimal(18,2) NOT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `currencyCode` varchar(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_B00548FA25A38F89` (`fund_id`),
  KEY `available_fundings` (`amountAvailable`,`id`),
  CONSTRAINT `FK_B00548FA25A38F89` FOREIGN KEY (`fund_id`) REFERENCES `Fund` (`id`)
)
