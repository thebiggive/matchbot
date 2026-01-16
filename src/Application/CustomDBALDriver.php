<?php

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use MatchBot\Application\CustomMySQLSchemaManager;
use Override;
use SensitiveParameter;

class CustomDBALDriver implements Driver
{
    private Driver\PDO\MySQL\Driver $pdoDriver;

    public function __construct()
    {
        $this->pdoDriver = new Driver\PDO\MySQL\Driver();
    }

    #[Override]
    public function getSchemaManager(Connection $conn, AbstractPlatform $platform): MySQLSchemaManager
    {
        return new CustomMySQLSchemaManager($conn, $platform);
    }

    #[\Override]
    public function connect(#[SensitiveParameter] array $params)
    {
        return $this->pdoDriver->connect($params);
    }

    #[\Override]
    public function getDatabasePlatform()
    {
        return $this->pdoDriver->getDatabasePlatform();
    }

    #[\Override]
    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->pdoDriver->getExceptionConverter();
    }
}
