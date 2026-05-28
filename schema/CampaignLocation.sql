-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `CampaignLocation` (
  `countryName` varchar(50) DEFAULT NULL,
  `regionCode` varchar(10) DEFAULT NULL,
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_6C25EDB1F639F774` (`campaign_id`),
  CONSTRAINT `FK_6C25EDB1F639F774` FOREIGN KEY (`campaign_id`) REFERENCES `Campaign` (`id`) ON DELETE CASCADE
)
