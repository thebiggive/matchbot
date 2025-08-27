-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `Charity` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `salesforceId` varchar(18) DEFAULT NULL,
  `salesforceLastPull` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `stripeAccountId` varchar(255) DEFAULT NULL,
  `hmrcReferenceNumber` varchar(7) DEFAULT NULL,
  `tbgClaimingGiftAid` tinyint(1) NOT NULL,
  `regulator` varchar(4) DEFAULT NULL,
  `regulatorNumber` varchar(10) DEFAULT NULL,
  `tbgApprovedToClaimGiftAid` tinyint(1) NOT NULL,
  `salesforceData` json NOT NULL,
  `logoUri` varchar(255) DEFAULT NULL,
  `websiteUri` varchar(255) DEFAULT NULL,
  `phoneNumber` varchar(255) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `address_city` varchar(255) DEFAULT NULL,
  `address_postalCode` varchar(255) DEFAULT NULL,
  `address_country` varchar(255) DEFAULT NULL,
  `emailAddress` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_4CC08E82D8961D21` (`salesforceId`),
  UNIQUE KEY `UNIQ_4CC08E8293A8A818` (`stripeAccountId`),
  UNIQUE KEY `UNIQ_4CC08E829EF7853B` (`hmrcReferenceNumber`),
  KEY `IDX_4CC08E82D8961D21` (`salesforceId`)
)
