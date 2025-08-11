<?php

namespace MatchBot;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "MatchBot API",
    version: "1.0.0",
    description: "The Big Give MatchBot API for donation matching and related functionality"
)]
#[OA\PathItem(
    path: "/v1"
)]
class OpenApiDefinition
{
    // This class exists solely to hold OpenAPI attributes
}
