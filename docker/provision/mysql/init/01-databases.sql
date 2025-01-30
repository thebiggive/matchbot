# create databases
CREATE DATABASE IF NOT EXISTS `matchbot`;
CREATE DATABASE IF NOT EXISTS `matchbot_test`;

# create root user and grant rights
CREATE USER 'root'@'%' IDENTIFIED BY 'tbgLocal123';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%';
