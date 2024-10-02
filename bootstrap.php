<?php

declare(strict_types=1);

// Instantiate PHP-DI ContainerBuilder
use DI\ContainerBuilder;
use Doctrine\DBAL\Types\Type;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

if (!in_array(getenv('APP_ENV'), ['local', 'test'], true)) { // Compile cache on staging & production
    $containerBuilder->enableCompilation(__DIR__ . '/var/cache');
}


// Set up settings
$settings = require __DIR__ . '/app/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/app/dependencies.php';
$dependencies($containerBuilder);

// Set up repositories
$repositories = require __DIR__ . '/app/repositories.php';
$repositories($containerBuilder);

if (! Type::hasType('uuid')) {
    Type::addType('uuid', Ramsey\Uuid\Doctrine\UuidType::class);
}

// Build PHP-DI Container instance
return $containerBuilder->build();
