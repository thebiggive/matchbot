<?php

namespace MatchBot\Domain;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class DonorAccountTest extends TestCase
{
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
