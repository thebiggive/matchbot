<?php

namespace MatchBot\Domain;

enum CampaignFamily: string
{
    case christmasChallenge = 'christmasChallenge';
    case greenMatchFund = 'greenMatchFund';
    case summerGive = 'summerGive';
    case womenGirls = 'womenGirls';
    case artsforImpact = 'artsforImpact';
    case smallCharity = 'smallCharity';
    case emergencyMatch = 'emergencyMatch';
    case mentalHealthFund = 'mentalHealthFund';
    case multiCurrency = 'multiCurrency';
    case imf = 'imf';
}
