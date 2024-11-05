<?php

namespace MatchBot\Domain;

use function Symfony\Component\String\s;

/**
 * Brand of payment card. Cases include all currently supported brands for Stripe, as well as the special
 * 'unknown' value for cards of other or unknown brands.
 *
 * Cases based on https://stripe.com/docs/api/errors#errors-payment_method-card-brand
 */
enum CardBrand: string
{
    case amex = 'amex';
    case diners = 'diners';
    case discover = 'discover';
    case eftpos_au = 'eftpos_au';
    case jcb = 'jcb';
    case mastercard = 'mastercard';
    case unionpay = 'unionpay';
    case visa = 'visa';
    case unknow = 'unknown';

    public static function fromNameOrNull(?string $brand): ?self
    {
        if ($brand === null) {
            return null;
        }

        return self::from($brand);
    }

    /**
     * Whether this is amex or not is important Amex is more expensive to process.
     */
    public function isAmex(): bool
    {
        return $this === self::amex;
    }
}
