<?php

namespace MatchBot\Domain;

use Assert\Assert;
use Assert\LazyAssertion;
use Assert\LazyAssertionException;

/**
 * Checks that a campaign as rendered to an array by new matchbot code is compatible with how it was
 * rendered by the SF API.
 */
class CampaignRenderCompatibilityChecker
{
    /**
     * @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected
     *
     * @throws LazyAssertionException
     */
    public static function checkCampaignHttpModelMatchesModelFromSF(
        array $actual,
        array $expected
    ): void {
        $lazyAsert = Assert::lazy();

        self::recursiveCompare(
            $actual,
            $expected,
            $lazyAsert
        );

        $lazyAsert->verifyNow();
    }

    /**
     * @param array<array-key, mixed> $actual
     * @param array<array-key, mixed> $expected
     * @param LazyAssertion $lazyAssert
     */
    private static function recursiveCompare(
        array $actual,
        array $expected,
        LazyAssertion $lazyAssert,
        string $path = '',
    ): void {
        /** @var mixed $expectedValue */
        foreach ($expected as $key => $expectedValue) {
            /** @var mixed $value */

            $value = \array_key_exists($key, $actual) ?
                $actual[$key] : '<UNDEFINED>';

            if (\is_array($expectedValue) && \is_array($value)) {
                self::recursiveCompare($value, $expectedValue, $lazyAssert, "{$key}.");
            } else {
                $lazyAssert->that($value)->eq($expectedValue, null, "{$path}{$key}");
            }
        }
    }
}
