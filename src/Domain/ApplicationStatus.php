<?php

namespace MatchBot\Domain;

enum ApplicationStatus: string
{
    case RegisterInterest = 'Register Interest';
    case InProgress = 'InProgress';
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case PendingApproval = 'Pending Approval';
    case MissedDeadline  = 'Missed Deadline';
}
