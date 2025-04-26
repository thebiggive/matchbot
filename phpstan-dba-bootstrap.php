<?php // phpstan-dba-bootstrap.php

use MatchBot\Application\Settings;
use staabm\PHPStanDba\DbSchema\SchemaHasherMysql;
use staabm\PHPStanDba\QueryReflection\PdoMysqlQueryReflector;
use staabm\PHPStanDba\QueryReflection\RuntimeConfiguration;
use staabm\PHPStanDba\QueryReflection\MysqliQueryReflector;
use staabm\PHPStanDba\QueryReflection\QueryReflection;
use staabm\PHPStanDba\QueryReflection\ReplayAndRecordingQueryReflector;
use staabm\PHPStanDba\QueryReflection\ReplayQueryReflector;
use staabm\PHPStanDba\QueryReflection\ReflectionCache;

require_once __DIR__ . '/vendor/autoload.php';
$cacheFile = __DIR__.'/.phpstan-dba.cache';

$config = new RuntimeConfiguration();
// $config->debugMode(true);
// $config->stringifyTypes(true);
// $config->analyzeQueryPlans(true);
// $config->utilizeSqlAst(true);

$queryCache = ReflectionCache::create($cacheFile);

if (! getenv('MYSQL_HOST')) {
    $reflector = QueryReflection::setupReflector(
        new ReplayQueryReflector(
            $queryCache
        ),
        $config);
} else {

    $settings = Settings::fromEnvVars(getenv());


    $dbname = $settings->doctrine['connection']['dbname'];
    $host = $settings->doctrine['connection']['host'];
    $PDO = new PDO(
        dsn: "mysql:host=$host;dbname=$dbname",
        username: $settings->doctrine['connection']['user'],
        password: $settings->doctrine['connection']['password']
    );

    $reflector = new ReplayAndRecordingQueryReflector(
        reflectionCache: $queryCache,
        queryReflector: new PdoMysqlQueryReflector($PDO),
        schemaHasher: new SchemaHasherMysql($PDO)
    );
}

QueryReflection::setupReflector(
    $reflector,
    $config
);
