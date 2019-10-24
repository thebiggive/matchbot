<?php

declare(strict_types=1);

use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder) {
    // TODO this?
    // Here we map our UserRepository interface to its in memory implementation
//    $containerBuilder->addDefinitions([
//        UserRepository::class => \DI\autowire(InMemoryUserRepository::class),
//    ]);
};
