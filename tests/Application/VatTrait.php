<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application;

trait VatTrait
{
    /**
     * Modifies given `$settings` to set realistic UK VAT rates (unit tests currently
     * have settings with no VAT by default).
     */
    protected function getUKLikeVATSettings(array $settings): array
    {
        $settings['stripe']['fee']['vat_percentage_live'] = '20';
        $settings['stripe']['fee']['vat_percentage_old'] = '0';
        $settings['stripe']['fee']['vat_live_date'] = (new \DateTime())->format('c');

        return $settings;
    }
}
