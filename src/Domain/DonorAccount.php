<?php

namespace MatchBot\Domain;
use Doctrine\ORM\Mapping as ORM;

/**
 * This is new, about to be brought into use.
 * @psalm-suppress PossiblyUnusedProperty
 *
 * Holds details of an account set at Stripe by a donor through our system so that they can transfer funds using a
 * bank transfer and later donate those funds (or parts of those funds) to charities.
 *
 * This class originally created for the use case of mapping from stripe IDs to email addresses and names, so that
 * we can send out confirmation emails when bank transfers are recieved into the account - but may well grow to support
 * other related uses.
 *
 * I would like to PHP Attributes instead of Annotations (Doctrine's Annotation Driver is deprecated and will be removed
 * in Doctrine 3 - but we have the ORM configured to read Annotations, and sadly it doesn't seem possible to simply set
 * it to read both, so using annotations for now. See https://stackoverflow.com/a/69284041/2526181
 *
 * @ORM\Entity(repositoryClass="DonorAccountRepository")
 */
class DonorAccount extends Model
{
    /**
     * @ORM\Column(type="string", length=256)
     */
    public readonly string $emailAddress;

    /**
     * @ORM\Column(type="string", length=50)
     */
    public readonly string $stripeCustomerId;

    public function __construct(string $emailAddress, string $stripeCustomerId)
    {
        $this->emailAddress = $emailAddress;
        $this->stripeCustomerId = $stripeCustomerId;
    }
}