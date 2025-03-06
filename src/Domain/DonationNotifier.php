<?php

namespace MatchBot\Domain;

class DonationNotifier {
    public static function emailCommandForCollectedDonation(Donation $donation): SendEmailCommand
    {
        if (! $donation->getDonationStatus()->isSuccessful()) {
            throw new \RuntimeException("{$donation} is not successful - cannot send success email");
        }
        return SendEmailCommand::donorDonationSuccess($donation);
    }
}