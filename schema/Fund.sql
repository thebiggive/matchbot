-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `Fund` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `salesforceId` varchar(18) DEFAULT NULL,
  `salesforceLastPull` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `fundType` varchar(255) NOT NULL,
  `currencyCode` varchar(3) NOT NULL,
  `allocationOrder` int NOT NULL,
  `slug` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_7CA0912ED8961D21` (`salesforceId`),
  KEY `allocationOrder` (`allocationOrder`)
)
