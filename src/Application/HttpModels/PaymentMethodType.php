<?php

declare(strict_types=1);

namespace MatchBot\Application\HttpModels;

enum PaymentMethodType
{
    case card;
    case customer_balance;
}
