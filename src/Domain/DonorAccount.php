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
 */
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: DonorAccountRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STRIPE_ID', columns: ['stripeCustomerId'])]
class DonorAccount extends Model
{
    use TimestampsTrait;

    #[ORM\Embedded(class: 'EmailAddress', columnPrefix: false)]
    public readonly EmailAddress $emailAddress;

    #[ORM\Embedded(class: 'DonorName')]
    public readonly DonorName $donorName;

    #[ORM\Embedded(class: 'StripeCustomerId', columnPrefix: false)]
    public readonly StripeCustomerId $stripeCustomerId;

    public function __construct(EmailAddress $emailAddress, DonorName $donorName, StripeCustomerId $stripeCustomerId)
    {
        $this->createdNow();
        $this->emailAddress = $emailAddress;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->donorName = $donorName;
    }
}
