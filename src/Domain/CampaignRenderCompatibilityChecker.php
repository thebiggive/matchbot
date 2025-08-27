<?php

namespace MatchBot\Domain;

use Assert\Assert;
use Assert\LazyAssertion;
use Assert\LazyAssertionException;
use MatchBot\Application\Environment;

/**
 * Checks that a campaign as rendered to an array by new matchbot code is compatible with how it was
 * rendered by the SF API.
 */
class CampaignRenderCompatibilityChecker
{
    private const array KEYS_TO_SKIP = [
        'x_isMetaCampaign',
        'isMetaCampaign',
        'isEmergencyIMF', // not used by FE,
        'amountRaised',  // don't need to check amount raised as it is being handled by matchbot and Salesforce data
                         // might not be identical
        'donationCount',  // may differ from SF - on dev env will be completly unrelated, in other envs donations
                         // will appear in matchbot count before SF knows them.

        'matchFundsRemaining', // Calculated from updated data in matchbot, not expected match exactly what salesforce
                               // shows at any moment although should be the same when both systems have had a few
                               // minutes to update after the last donation.
        'slug', // new in SF API not needed by FE
        'campaignFamily', // new in SF API not needed by FE

        'parentUsesSharedFunds', // parent stuff all requires fetching metacampaign separately, we don't yet have
        'parentTarget',         // metacampaigns populated in matchbot db.
        'target', // matchbot calculated target relies on data that we haven't yet pulled from SF, so will show zero for now.
        'parentAmountRaised',
        'parentDonationCount',
        'parentMatchFundsRemaining',
        'matchFundsTotal', // checking this fails because our campaign reconstructed from SF data doesn't have a numeric ID we can't find its funds.
        'totalAdjustment', // used for calculation in matchbot for metacampaigns, not output to FE by matchbot.
        'championName', // Diverging for SCW25 patched funders for now
        'totalFundraisingTarget', // totalFundraisingTarget and the following four are all sent from SF to matchbot enable matchbot calculating campaign targets, not required by FE. See MAT-435
        'imfCampaignTargetOverride',
        'totalFundingAllocation',
        'amountPledged',
        'totalMatchedFundsAvailable',
        'masterCampaignStatus', // sent by SF just to allow matchbot to calculate status of a metacampaign at output time
        'campaignStatus', // sent by SF just to allow matchbot to calculate status of a metacampaign at output time
        'pinPosition',
        'championPagePinPosition',
        'relatedApplicationStatus', // sent by SF to allow matchbot to count campaigns etc, not needed in FE.
        'relatedApplicationCharityResponseToOffer', // sent by SF to allow matchbot to count campaigns etc, not needed in FE.
    ];

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

            if ($key === 'title' && $expectedValue === null && $value === "Untitled campaign") {
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

            if ($key === 'website' && \is_string($expectedValue) && is_null($value)) {
                // presumably our value is null because the value from SF is a malformed URL.
                continue;
            }

            if (\in_array($key, self::KEYS_TO_SKIP, true)) {
                continue;
            }

            if (\is_array($expectedValue) && \is_array($value)) {
                self::recursiveCompare($value, $expectedValue, $lazyAssert, "{$key}.");
            } else {
                $lazyAssert->that($value)->eq($expectedValue, null, "{$path}{$key}");
            }
        }
    }
}
