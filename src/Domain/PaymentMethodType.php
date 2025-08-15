<?php

declare(strict_types=1);

namespace MatchBot\Domain;

enum PaymentMethodType: string
{
    case Card = 'card';
    case CustomerBalance = 'customer_balance';
    case PayByBank = 'pay_by_bank';
}
