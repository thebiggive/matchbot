<?php

require __DIR__ . '/../vendor/autoload.php';

if (getenv('CI') === false) {
    // In local dev env we use a different DB name to avoid interfering with manual tests. In CI env of course
    // there are no manual tests so we use the default db name `matchbot`
    putenv("MYSQL_SCHEMA=matchbot_test");
}

if (! in_array(getenv('APP_ENV'), ['local', 'test'], true)) {
    throw new \Exception("Don't run integration tests in live!");
}

echo "Using DB schema " . (string) getenv('MYSQL_SCHEMA') . "\n";
