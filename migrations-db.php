#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @var \DI\Container $psr11App */
$psr11App = require __DIR__ . '/bootstrap.php';

return $psr11App->get(\Doctrine\DBAL\Connection::class);
