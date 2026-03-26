<?php

namespace MatchBot\Domain;

enum PaymentServiceProvider: string
{
    case Stripe = 'stripe';
    case Ryft = 'ryft';
}
