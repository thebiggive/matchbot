<?php

namespace MatchBot;

use OpenApi\Attributes as OA;

/**
 * @psalm-suppress UnusedClass
 *
 * Merging code docs with hard-coded requires some global OA attributes but we mostly want to ignore or replace them.
 */
#[OA\Info(
    title: "API (dummy title)",
    version: "0.0.0" // Dummy version number
)]
#[OA\Server(
    url: "https://matchbot-staging.thebiggive.org.uk",
    description: "Dummy path staging server"
)]
#[OA\PathItem(path: "/dummy-path")]
class OpenApi
{
    // This class exists solely to hold OpenAPI attributes
}
