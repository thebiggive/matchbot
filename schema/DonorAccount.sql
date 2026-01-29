-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `DonorAccount` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `stripeCustomerId` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `createdAt` datetime NOT NULL,
  `updatedAt` datetime NOT NULL,
  `donorName_first` varchar(255) NOT NULL,
  `donorName_last` varchar(255) NOT NULL,
  `regularGivingPaymentMethod` varchar(255) DEFAULT NULL,
  `billingCountryCode` varchar(2) DEFAULT NULL,
  `homeAddressLine1` varchar(255) DEFAULT NULL,
  `homePostcode` varchar(255) DEFAULT NULL,
  `billingPostcode` varchar(255) DEFAULT NULL,
  `uuid` char(36) NOT NULL COMMENT '(DC2Type:uuid)',
  `homeIsOutsideUK` tinyint(1) DEFAULT NULL,
  `organisationName` varchar(255) DEFAULT NULL,
  `isOrganisation` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_STRIPE_ID` (`stripeCustomerId`),
  UNIQUE KEY `UNIQ_6FA7403D17F50A6` (`uuid`)
)
