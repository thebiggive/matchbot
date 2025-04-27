-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `Campaign_CampaignFunding` (
  `campaignfunding_id` int unsigned NOT NULL,
  `campaign_id` int unsigned NOT NULL,
  PRIMARY KEY (`campaignfunding_id`,`campaign_id`),
  KEY `IDX_3364399584C3B9E4` (`campaignfunding_id`),
  KEY `IDX_33643995F639F774` (`campaign_id`),
  CONSTRAINT `FK_3364399584C3B9E4` FOREIGN KEY (`campaignfunding_id`) REFERENCES `CampaignFunding` (`id`),
  CONSTRAINT `FK_33643995F639F774` FOREIGN KEY (`campaign_id`) REFERENCES `Campaign` (`id`)
)
