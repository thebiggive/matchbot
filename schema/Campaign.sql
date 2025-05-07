-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `Campaign` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `charity_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `startDate` datetime NOT NULL,
  `endDate` datetime NOT NULL,
  `isMatched` tinyint(1) NOT NULL,
  `salesforceId` varchar(18) DEFAULT NULL,
  `salesforceLastPull` datetime DEFAULT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `currencyCode` varchar(3) NOT NULL,
  `ready` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(64) DEFAULT NULL,
  `isRegularGiving` tinyint(1) NOT NULL,
  `regularGivingCollectionEnd` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `thankYouMessage` varchar(500) DEFAULT NULL,
  `salesforceData` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_E663708BD8961D21` (`salesforceId`),
  KEY `IDX_E663708BF5C97E37` (`charity_id`),
  KEY `end_date_and_is_matched` (`endDate`,`isMatched`),
  CONSTRAINT `FK_E663708BF5C97E37` FOREIGN KEY (`charity_id`) REFERENCES `Charity` (`id`)
)
