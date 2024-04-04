<?php

declare(strict_types=1);

namespace MatchBot\Application\Fees;

use Assert\AssertionFailedException;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assertion;

/**
 * @psalm-immutable
 */
class Calculator
{
    private const string VAT_PERCENTAGE = '20';

    private const string STRIPE_FEE_MAIN_PERCENTAGE_AMEX_OR_NON_UK_EU = '3.2';

    private const string STRIPE_FEE_GIFT_AID_PERCENTAGE = '0.75'; // 3% of Gift Aid amount.

    private const string STRIPE_FEE_MAIN_PERCENTAGE_STANDARD = '1.5';

    private const array STRIPE_FEES_FIXED = [
        // Based on Stripe support email 9/4/21.
        'CHF' => '0.3',
        'DKK' => '1.8',
        'EUR' => '0.25',
        'GBP' => '0.2', // Baseline fee in pounds
        'NOK' => '1.8',
        'SEK' => '1.8',
        'USD' => '0.3',
    ];

    private const string STRIPE_FEES_DEFAULT = '0.2';

    /** @var string[]   EU + GB ISO 3166-1 alpha-2 country codes */
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE',
        'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL',
        'PT', 'RO', 'RU', 'SI', 'SK', 'ES', 'SE',
        'CH', 'GB',
    ];

    /**
     * From https://stripe.com/docs/api/errors#errors-payment_method-card-brand
     */
    public const STRIPE_CARD_BRANDS = [
        'amex', 'diners', 'discover', 'eftpos_au', 'jcb', 'mastercard', 'unionpay', 'visa', 'unknown'
    ];

    /**
     * @param numeric-string $feePercentageOverride
     */
    public static function calculate(
        string $psp,
        ?string $cardBrand,
        ?string $cardCountry,
        string $amount,
        string $currencyCode,
        bool $hasGiftAid, // Whether donation has Gift Aid *and* a fee is to be charged to claim it.
        ?string $feePercentageOverride = null,
    ): Fees {
        $calculator = new self(
            psp: $psp,
            cardBrand: $cardBrand,
            cardCountry: $cardCountry,
            amount: $amount,
            currencyCode: $currencyCode,
            hasGiftAid: $hasGiftAid,
            feePercentageOverride: $feePercentageOverride,
        );

        return new Fees(
            coreFee: $calculator->getCoreFee(),
            feeVat: $calculator->getFeeVat()
        );
    }

    /**
     * We can consider removing all instance properties and methods and relying on static methods and local vars only -
     * a static calculator would be clearer. For now, I've hidden the instance in this private method - there's no
     * public way to get a Calculator instance.

     * @param numeric-string|null $feePercentageOverride
     */
    private function __construct(
        string $psp,
        readonly private ?string $cardBrand,
        readonly private ?string $cardCountry,
        readonly private string $amount,
        readonly private string $currencyCode,
        readonly private bool $hasGiftAid, // Whether donation has Gift Aid *and* a fee is to be charged to claim it.
        readonly private ?string $feePercentageOverride = null,
    ) {
        if (! in_array($this->cardBrand, [...self::STRIPE_CARD_BRANDS, null], true)) {
            throw new \UnexpectedValueException(
                'Unexpected card brand, expected brands are ' .
                implode(', ', self::STRIPE_CARD_BRANDS)
            );
        }

        Assertion::eq(
            $psp,
            'stripe',
            'Only Stripe PSP is supported as don\'t know what fees to charge for other PSPs.'
        );
    }

    private function getCoreFee(): string
    {
        $giftAidFee = '0.00';
        $feeAmountFixed = '0.00';

        if ($this->feePercentageOverride === null) {
            // Standard, dynamic fee model. Typically includes fixed amount. Historically may include
            // a fee on Gift Aid. May vary by card type & country.

            $currencyCode = strtoupper($this->currencyCode); // Just in case (Stripe use lowercase internally).
            if (array_key_exists($currencyCode, self::STRIPE_FEES_FIXED)) {
                $feeAmountFixed = self::STRIPE_FEES_FIXED[$currencyCode];
            } else {
                $feeAmountFixed = self::STRIPE_FEES_DEFAULT;
            }

            $feeRatio = bcdiv(self::STRIPE_FEE_MAIN_PERCENTAGE_STANDARD, '100', 3);
            if ($this->cardBrand === 'amex' || !$this->isEU($this->cardCountry)) {
                $feeRatio = bcdiv(self::STRIPE_FEE_MAIN_PERCENTAGE_AMEX_OR_NON_UK_EU, '100', 3);
            }

            if ($this->hasGiftAid) {
                // 4 points needed to handle overall percentages of GA fee like 0.75% == 0.0075 ratio.
                $giftAidFee = bcmul(
                    bcdiv(self::STRIPE_FEE_GIFT_AID_PERCENTAGE, '100', 4),
                    $this->amount,
                    3,
                );
            }
        } else {
            // Alternative fixed % model. `$giftAidFee` and `$feeAmountFixed` remain zero.
            // Amount given is inclusive of any tax, so subtract it to get a net value.
            $vatRatio = bcdiv($this->getFeeVatPercentage(), '100', 3);
            $vatRatioPlusOne = bcadd('1', $vatRatio, 2);
            $grossFeeRatio = bcdiv($this->feePercentageOverride, '100', 3);

            $feeRatioBeforeOffest = bcdiv($grossFeeRatio, $vatRatioPlusOne, 10);
            // To get rounding correct (by standard accounting calculations), we need to 'round up'
            // slightly so that e.g a ratio of 0.0416666666 (as resulting from UK 20% rate) becomes
            // 0.0416666667.
            $feeRatio = bcadd($feeRatioBeforeOffest, '0.0000000001', 10);
        }

        // bcmath truncates values beyond the scale it's working at, so to get x.x% and round
        // in the normal mathematical way we need to start with 3 d.p. scale and round with a
        // workaround.
        $feeAmountFromPercentageComponent = $this->roundAmount(
            bcmul($this->amount, $feeRatio, 3)
        );

        // Charity fee calculated as:
        // Fixed fee amount + proportion of base donation amount + Gift Aid fee (for Stripe this is £0.00)
        return $this->roundAmount(
            bcadd(bcadd($feeAmountFixed, $feeAmountFromPercentageComponent, 3), $giftAidFee, 3)
        );
    }

    private function getFeeVat(): string
    {
        // We need to handle flat, inc-VAT fee logic differently to avoid rounding issues.
        // In this case we work back from the core fee we've derived and subtract it to get
        // the VAT amount. This is not necessarily the same result as adding the VAT % to
        // the *rounded* net fee.
        if ($this->feePercentageOverride) {
            $grossFeeRatio = bcdiv($this->feePercentageOverride, '100', 3);
            $grossFeeAmount = $this->roundAmount(bcmul($this->amount, $grossFeeRatio, 3));

            return bcsub($grossFeeAmount, $this->getCoreFee(), 2);
        }

        // Standard, non-flat-fee logic.
        $vatRatio = bcdiv($this->getFeeVatPercentage(), '100', 3);

        return $this->roundAmount(bcmul($vatRatio, $this->getCoreFee(), 3));
    }

    private function getFeeVatPercentage(): string
    {
        $currencyCode = strtoupper($this->currencyCode); // Just in case (Stripe use lowercase internally).
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
     * @param string $amount    Simplified from https://stackoverflow.com/a/51390451/2803757 for
     *                          fixed scale and only positive inputs.
     * @return string
     */
    #[Pure] private function roundAmount(string $amount): string
    {
        $e = '1000'; // Base 10 ^ 3

        return bcdiv(bcadd(bcmul($amount, $e, 0), '5'), $e, 2);
    }

    /**
     * @param string|null   $cardCountry    ISO 3166-1 alpha-2 country code, or null.
     * @return bool Whether the charge was made using an EU card
     */
    #[Pure] private function isEU(?string $cardCountry): bool
    {
        if ($cardCountry === null) {
            // Default to 1.5% calculation if card country is not known yet OR remains
            // null because the donation is settled from a Customer's cash balance.
            return true;
        }

        return in_array($cardCountry, self::EU_COUNTRY_CODES, true);
    }
}
