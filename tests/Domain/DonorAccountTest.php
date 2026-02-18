<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\StripeCustomerId;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class DonorAccountTest extends TestCase
{
    public function testIndividualDonorAccountMustHaveName(): void
    {
        $this->expectExceptionMessage('Donor name cannot be null for individuals');
        new DonorAccount(
            PersonId::ofUUID(Uuid::uuid4()),
            EmailAddress::of('email@example.com'),
            null,
            StripeCustomerId::of('cus_123'),
            null,
            false,
        );
    }
    public function testOrgDonorAccountMustHaveOrganisationName(): void
    {
        $this->expectExceptionMessage('Organisation name cannot be null for organisations');
        new DonorAccount(
            PersonId::ofUUID(Uuid::uuid4()),
            EmailAddress::of('email@example.com'),
            DonorName::of('Joe', 'Blogs'),
            StripeCustomerId::of('cus_123'),
            null,
            true,
        );
    }
}
