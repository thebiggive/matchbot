<?php

namespace MatchBot;

/**
 * @psalm-suppress UnusedClass
 *
 * @OA\Info(
 *     title="MatchBot API",
 *     version="1.0.0",
 *     description="Microservice providing donation matching and related APIs",
 *     @OA\Contact(
 *         name="The Big Give",
 *         url="https://www.thebiggive.org.uk",
 *         email="tech@thebiggive.org.uk"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://github.com/thebiggive/matchbot/blob/main/LICENSE"
 *     )
 * )
 *
 * @OA\Server(
 *     url="https://matchbot.thebiggive.org.uk",
 *     description="Production server"
 * )
 *
 * @OA\Server(
 *     url="https://matchbot-staging.thebiggive.org.uk",
 *     description="Staging server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 *
 * @OA\Tag(
 *     name="donations",
 *     description="Donation operations"
 * )
 *
 * @OA\Tag(
 *     name="campaigns",
 *     description="Campaign operations"
 * )
 *
 * @OA\Tag(
 *     name="charities",
 *     description="Charity operations"
 * )
 *
 * @OA\PathItem(
 *     path="/placeholder"
 * )
 */
class OpenApi
{
    // This class exists solely to hold OpenAPI annotations
}
