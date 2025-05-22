-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `donation-summary-no-orm` AS select `Donation`.`id` AS `id`,`Donation`.`salesforceId` AS `salesforceId`,`Donation`.`createdAt` AS `createdAt`,`Donation`.`amount` AS `amount`,`Donation`.`donationStatus` AS `donationStatus`,`Charity`.`name` AS `CharityName`,`Campaign`.`name` AS `CampaignName` from ((`Donation` join `Campaign` on((`Campaign`.`id` = `Donation`.`campaign_id`))) join `Charity` on((`Charity`.`id` = `Campaign`.`charity_id`)))