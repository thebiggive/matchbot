<?php

declare(strict_types=1);

namespace MatchBot\Application\Fees;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;

/**
 * Calculates fees to charge charities per donation. For public facing explanation of fee structure see
 * https://biggive.org/our-fees/
 *
 * @psalm-immutable
 */
class Calculator
{
    private const string VAT_PERCENTAGE = '20';

    private const string FEE_MAIN_PERCENTAGE_AMEX_OR_NON_UK_EU = '3.2';

    private const string FEE_GIFT_AID_PERCENTAGE = '0.75'; // 3% of Gift Aid amount.

    private const string FEE_MAIN_PERCENTAGE_STANDARD = '1.5';

    /** @var string[]   Major currency unit (e.g. pounds) fee charged *by us* for Stripe credit/debit
     *                  card donations. These values were chosen based on a Stripe support email about
     *                  their own core fees in mid 2021 BUT they don't necessarily reflect what Stripe
     *                  charge *us* due to special contract arrangements.
     */
    private const array FEES_FIXED = [
        'EUR' => '0.25',
        'GBP' => '0.2', // Baseline fee in pounds for recharge; not necessarily exactly what Stripe charged BG.
        'SEK' => '1.8',
        'USD' => '0.3',
    ];

    /**
     * @param numeric-string $amount
     */
    public static function calculate(
        string $psp,
        ?CardBrand $cardBrand,
        ?Country $cardCountry,
        string $amount,
        string $currencyCode,
        bool $hasGiftAid, // Whether donation has Gift Aid *and* a fee is to be charged to claim it.
    ): Fees {
        Assertion::eq(
            $psp,
            'stripe',
            'Only Stripe PSP is supported as don\'t know what fees to charge for other PSPs.'
        );

        $coreFee = self::getCoreFee(
            amount: $amount,
            currencyCode: $currencyCode,
            cardBrand: $cardBrand,
            cardCountry: $cardCountry,
            hasGiftAid: $hasGiftAid
        );

        // note at this point coreFee has been rounded to the nearest penny before we calculate VAT.

        $feeVat = self::getFeeVat(
            coreFee: $coreFee,
            currencyCode: $currencyCode
        );

        return new Fees(
            coreFee: $coreFee,
            feeVat: $feeVat,
        );
    }

    /**
     * @param numeric-string $amount
     * @return numeric-string
     */
    private static function getCoreFee(
        string $amount,
        string $currencyCode,
        ?CardBrand $cardBrand,
        ?Country $cardCountry,
        bool $hasGiftAid
    ): string {
        $giftAidFee = '0.00';

        // Standard, dynamic fee model. Typically includes fixed amount. Historically may include
        // a fee on Gift Aid. May vary by card type & country.

        $currencyCode = strtoupper($currencyCode); // Just in case (Stripe use lowercase internally).

        self::assertIsGBPOrInUnitTest($currencyCode);

        // Currency code has been compulsory for some time.
        Assertion::keyExists(self::FEES_FIXED, $currencyCode);
        $feeAmountFixed = self::FEES_FIXED[$currencyCode];

        $feeRatio = bcdiv(self::FEE_MAIN_PERCENTAGE_STANDARD, '100', 3);
        if ($cardBrand?->isAmex() || !self::isEU($cardCountry)) {
            $feeRatio = bcdiv(self::FEE_MAIN_PERCENTAGE_AMEX_OR_NON_UK_EU, '100', 3);
        }

        if ($hasGiftAid) {
            // 4 points needed to handle overall percentages of GA fee like 0.75% == 0.0075 ratio.
            $giftAidFee = bcmul(
                bcdiv(self::FEE_GIFT_AID_PERCENTAGE, '100', 4),
                $amount,
                3,
            );
        }

        // bcmath truncates values beyond the scale it's working at, so to get x.x% and round
        // in the normal mathematical way we need to start with 3 d.p. scale and round with a
        // workaround.
        $feeAmountFromPercentageComponent = self::roundAmount(
            bcmul($amount, $feeRatio, 3)
        );

        // Charity fee calculated as:
        // Fixed fee amount + proportion of base donation amount + Gift Aid fee (for Stripe this is £0.00)
        return self::roundAmount(
            bcadd(bcadd($feeAmountFixed, $feeAmountFromPercentageComponent, 3), $giftAidFee, 3)
        );
    }

    /**
     * @param numeric-string $coreFee
     * @param string $currencyCode
     * @return numeric-string
     */
    private static function getFeeVat(string $coreFee, string $currencyCode): string
    {
        // Standard, non-flat-fee logic.
        $vatRatio = bcdiv(self::getFeeVatPercentage($currencyCode), '100', 3);

        return self::roundAmount(bcmul($vatRatio, $coreFee, 3));
    }

    /**
     * @return numeric-string
     */
    private static function getFeeVatPercentage(string $currencyCode): string
    {
        $currencyCode = strtoupper($currencyCode); // Just in case (Stripe use lowercase internally).
        $currenciesIncurringFeeVat = ['EUR', 'GBP'];
        if (!in_array($currencyCode, $currenciesIncurringFeeVat, true)) {
            return '0';
        }

        return self::VAT_PERCENTAGE;
    }

    /**
     * Takes a bcmath string amount with 3 or more decimal places and rounds to
     * 2 places, with 0.005 rounding up and below rounding down.
     *
     * @param numeric-string $amount    Simplified from https://stackoverflow.com/a/51390451/2803757 for
     *                          fixed scale and only positive inputs.
     * @return numeric-string
     */
    #[Pure] private static function roundAmount(string $amount): string
    {
        $e = '1000'; // Base 10 ^ 3

        return bcdiv(bcadd(bcmul($amount, $e, 0), '5'), $e, 2);
    }

    private static function isEU(?Country $cardCountry): bool
    {
        if ($cardCountry === null) {
            // Default to 1.5% calculation if card country is not known yet OR remains
            // null because the donation is settled from a Customer's cash balance.
            return true;
        }

        return $cardCountry->isEUOrUK();
    }

    /**
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
        throw new \Exception("Don't construct, use static methods only");
    }

    private static function assertIsGBPOrInUnitTest(string $currencyCode): void
    {
        if (!defined('RUNNING_UNIT_TESTS')) {
            Assertion::same(
                'GBP',
                $currencyCode,
                "$currencyCode not supported, only GBP supported for fee calculations. Other currency fees would "
                . "need to be documented at https://biggive.org/our-fees/"
            );
        }
    }
}
