<?php

declare(strict_types=1);

namespace MatchBot\Application\Fees;

use JetBrains\PhpStorm\Pure;

class Calculator
{
    /** @var string[]   EU + GB ISO 3166-1 alpha-2 country codes */
    private array $euISOs = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE',
        'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL',
        'PT', 'RO', 'RU', 'SI', 'SK', 'ES', 'SE',
        'CH', 'GB',
    ];

    private array $pspFeeSettings;

    public function __construct(
        array $settings,
        string $psp,
        private ?string $cardBrand,
        private ?string $cardCountry,
        private string $amount,
        private string $currencyCode,
        private bool $hasGiftAid, // Whether donation has Gift Aid *and* a fee is to be charged to claim it.
        private ?float $feePercentageOverride = null,
    ) {
        $this->pspFeeSettings = $settings[$psp]['fee'];
    }

    public function getCoreFee(): string
    {
        $giftAidFee = '0.00';
        $feeAmountFixed = '0.00';

        if ($this->feePercentageOverride === null) {
            // Standard, dynamic fee model. Typically includes fixed amount. Historically may include
            // a fee on Gift Aid. May vary by card type & country.

            $currencyCode = strtoupper($this->currencyCode); // Just in case (Stripe use lowercase internally).
            if (array_key_exists($currencyCode, $this->pspFeeSettings['fixed'])) {
                $feeAmountFixed = $this->pspFeeSettings['fixed'][$currencyCode];
            } else {
                $feeAmountFixed = $this->pspFeeSettings['fixed']['default'];
            }

            $feeRatio = bcdiv($this->pspFeeSettings['main_percentage_standard'], '100', 3);
            if (
                isset($this->pspFeeSettings['main_percentage_amex_or_non_uk_eu']) &&
                ($this->cardBrand === 'amex' || !$this->isEU($this->cardCountry))
            ) {
                $feeRatio = bcdiv($this->pspFeeSettings['main_percentage_amex_or_non_uk_eu'], '100', 3);
            }

            if ($this->hasGiftAid) {
                // 4 points needed to handle overall percentages of GA fee like 0.75% == 0.0075 ratio.
                $giftAidFee = bcmul(
                    bcdiv($this->pspFeeSettings['gift_aid_percentage'], '100', 4),
                    $this->amount,
                    3,
                );
            }
        } else {
            // Alternative fixed % model. `$giftAidFee` and `$feeAmountFixed` remain zero.
            // Amount given is inclusive of any tax, so subtract it to get a net value.
            $vatRatio = bcdiv($this->getFeeVatPercentage(), '100', 3);
            $vatRatioPlusOne = bcadd('1', $vatRatio, 2);
            $grossFeeRatio = bcdiv((string)$this->feePercentageOverride, '100', 3);

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

    public function getFeeVat(): string
    {
        // We need to handle flat, inc-VAT fee logic differently to avoid rounding issues.
        // In this case we work back from the core fee we've derived and subtract it to get
        // the VAT amount. This is not necessarily the same result as adding the VAT % to
        // the *rounded* net fee.
        if ($this->feePercentageOverride) {
            $grossFeeRatio = bcdiv((string)$this->feePercentageOverride, '100', 3);
            $grossFeeAmount = $this->roundAmount(bcmul($this->amount, $grossFeeRatio, 3));

            return bcsub($grossFeeAmount, $this->getCoreFee(), 2);
        }

        // Standard, non-flat-fee logic.
        $vatRatio = bcdiv($this->getFeeVatPercentage(), '100', 3);

        return $this->roundAmount(bcmul($vatRatio, $this->getCoreFee(), 3));
    }

    private function getFeeVatPercentage(): string
    {
        if (empty($this->pspFeeSettings['vat_live_date'])) {
            return '0'; // VAT does not apply to the current PSP's fees.
        }

        $currencyCode = strtoupper($this->currencyCode); // Just in case (Stripe use lowercase internally).
        $currenciesIncurringFeeVat = ['EUR', 'GBP'];
        if (!in_array($currencyCode, $currenciesIncurringFeeVat, true)) {
            return '0';
        }

        $switchDate = new \DateTime($this->pspFeeSettings['vat_live_date']);
        if (new \DateTime('now') >= $switchDate) {
            return $this->pspFeeSettings['vat_percentage_live'];
        }

        return $this->pspFeeSettings['vat_percentage_old'];
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

        return in_array($cardCountry, $this->euISOs, true);
    }
}
