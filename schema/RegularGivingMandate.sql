-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `RegularGivingMandate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT '(DC2Type:uuid)',
  `campaignId` varchar(255) NOT NULL,
  `charityId` varchar(255) NOT NULL,
  `giftAid` tinyint(1) NOT NULL,
  `salesforceLastPush` datetime DEFAULT NULL,
  `salesforcePushStatus` varchar(255) NOT NULL,
  `salesforceId` varchar(18) DEFAULT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `personid` char(36) NOT NULL COMMENT '(DC2Type:uuid)',
  `donationAmount_amountInPence` int NOT NULL,
  `donationAmount_currency` varchar(3) NOT NULL,
  `dayOfMonth` smallint NOT NULL,
  `activeFrom` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `status` varchar(255) NOT NULL,
  `donationsCreatedUpTo` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `tbgComms` tinyint(1) NOT NULL,
  `charityComms` tinyint(1) NOT NULL,
  `isMatched` tinyint(1) NOT NULL,
  `cancellationType` varchar(50) DEFAULT NULL,
  `cancellationReason` varchar(500) DEFAULT NULL,
  `cancelledAt` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `paymentDateOffsetMonths` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_F638CA2BD17F50A6` (`uuid`),
  UNIQUE KEY `UNIQ_F638CA2BD8961D21` (`salesforceId`),
  UNIQUE KEY `person_id_if_active` (((case when (`status` in (_utf8mb4'active',_utf8mb4'pending')) then concat(`personid`,_utf8mb4':',`campaignId`) end))),
  KEY `uuid` (`uuid`),
  KEY `donationsCreatedUpTo` (`donationsCreatedUpTo`)
)
