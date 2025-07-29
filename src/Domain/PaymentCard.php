<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * @psalm-immutable
 *
 * Designed to be embeddable but in practice can't be embedded in our donation ORM entity until we upgrade to Doctrine
 * ORM 3 for the nullable embeddable support, since not all donations use payment cards (and even if they did we have
 * new and old donations with unknown cards)
 */
#[Embeddable]
readonly class PaymentCard
{
    public function __construct(
        #[Column()]
        public CardBrand $brand,
        #[Column()]
        public Country $country,
    ) {
    }
}
