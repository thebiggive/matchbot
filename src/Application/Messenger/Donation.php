<?php

namespace MatchBot\Application\Messenger;

/**
 * For now, this is just a copy of `ClaimBot\Messenger\Donation`. We may want to think
 * about abstracting the model out to a shared dependency later.
 */
class Donation
{
    public string $id;

    /** @var string Donation date, YYYY-MM-DD. */
    public string $donation_date;

    public string $title;
    public string $first_name;
    public string $last_name;
    public string $house_no;
    public string $postcode;
    public bool $overseas = false;
    public float $amount;
    public bool $sponsored = false;
    public ?string $org_hmrc_ref = null;
}
