<?php

declare(strict_types=1);

namespace MatchBot\Domain;

enum RefundScope: string
{
    case full = 'full';
    case tip = 'tip';
}
