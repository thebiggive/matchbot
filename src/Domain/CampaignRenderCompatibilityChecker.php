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
            if (is_string($key) && \str_starts_with(haystack: $key, needle: 'x_')) {
                // field is intended for use within matchbot only, does not need to be emitted to FE
                continue;
            }

            if ($key === 'amountRaised') {
                // don't need to check amount raised as it is being handled by matchbot and Salesforce data might not be identical
                continue;
            }

            /** @var mixed $value */

            $value = \array_key_exists($key, $actual) ?
                $actual[$key] : '<UNDEFINED>';


            if (
                \is_null($expectedValue) &&
                is_string($value) &&
                \str_starts_with(haystack: $value, needle: '1970-01-01')
            ) {
                // in some cases (e.g. early campaign previews) SF sends null values for date time. The Matchbot
                // domain model doesn't allow easily replicating that, so we send 1970 which is what FE would treat
                // null as anyway.
                continue;
            }

            if ($key === 'postalAddress') {
                // postalAddress is not required by FE, so not output by matchbot.
                // We can't output a postalAddress that would match what SF sends in all cases as MB does nullifies
                // address if first line is missing.
                $expectedValue = "<UNDEFINED>";
            }

            if ($key === 'donationCount') {
                // may differ from SF - on dev env will be completly unrelated, in other envs donations will appear
                // in matchbot count before SF knows them.
                continue;
            }

            if ($key === 'website' && \is_string($expectedValue) && \is_string($value)) {
                // \Laminas\Diactoros\Uri always converts the hostname to lowercase since thats how websites are
                // registered. Although uppercase can be useful for making longer hostnames more readable or
                // stylish its probably not essential for us to reproduce the exact casing as typed, so
                // we do a case-insensitive check here.
                $value = \strtolower($value);
                $expectedValue = \strtolower($expectedValue);
            }

            if (\is_array($expectedValue) && \is_array($value)) {
                self::recursiveCompare($value, $expectedValue, $lazyAssert, "{$key}.");
            } else {
                $lazyAssert->that($value)->eq($expectedValue, null, "{$path}{$key}");
            }
        }
    }
}
