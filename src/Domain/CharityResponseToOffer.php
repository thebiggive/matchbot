<?php

namespace MatchBot\Domain;

enum CharityResponseToOffer: string
{
    case Accepted = 'Accepted';
    case Rejected = 'Rejected';
}
