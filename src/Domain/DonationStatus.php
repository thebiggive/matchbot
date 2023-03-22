<?php

namespace MatchBot\Domain;

/**
 * @link https://docs.google.com/document/d/11ukX2jOxConiVT3BhzbUKzLfSybG8eie7MX0b0kG89U/edit?usp=sharing
 * @todo Consider vs. Stripe options
 */
enum DonationStatus: string
{
    case Cancelled =    'Cancelled';
    case Chargedback =    'Chargedback';
    case Collected =    'Collected';
    case Failed =    'Failed';
    case NotSet = "NotSet";
    case Paid =    'Paid';
    case Pending = "Pending";
    case PendingCancellation =    'PendingCancellation';
    case Refunded = "Refunded";
    case RefundingPending =    'RefundingPending';
    case Reserved = 'Reserved';
}
