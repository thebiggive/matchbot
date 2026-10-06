<?php

namespace MatchBot\Domain;

enum RefundScope: string
{
    case full = 'full';
    case tip = 'tip';
}
