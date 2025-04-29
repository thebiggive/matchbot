-- Auto generated file, do not edit.
-- run ./matchbot matchbot:write-schema-files to update

-- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
-- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
-- information only.

CREATE TABLE `EmailVerificationToken` (
  `id` int NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `emailAddress` varchar(255) NOT NULL,
  `randomCode` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
)
