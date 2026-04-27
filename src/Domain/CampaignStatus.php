<?php

namespace MatchBot\Domain;

/**
 * Initially based on the possible values of CCampaign.Status in Salesforce, but may diverge from that in future.
 */
enum CampaignStatus: string
{
    case Active = 'Active';
    case Expired = 'Expired';
    case Preview = 'Preview';
}
