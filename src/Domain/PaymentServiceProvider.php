<?php

namespace MatchBot\Domain;

enum PaymentServiceProvider: string
{
    public const VALUES = [self::Stripe->value, self::Ryft->value];
    case Stripe = 'stripe';
    case Ryft = 'ryft';
}
