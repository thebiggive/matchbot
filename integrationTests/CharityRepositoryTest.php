<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;

/**
 * CharityRepository probably doesn't really need testing since it has no code of its own, but this
 * is a proof of concept integration test + serves to check that the database connection and ORM are configured
 * correctly and migrations have been run.
 */
class CharityRepositoryTest extends IntegrationTest
{
    public function testItDoesNotFindCharityThatDoesNotExist(): void
    {
        $sut = $this->getService(CharityRepository::class);

        $found = $sut->find("non-existant-id");

        $this->assertNull($found);
    }

    public function testItFindsACharity(): void
    {
        // arrange
        $sut = $this->getService(CharityRepository::class);

        $charity = \MatchBot\Tests\TestCase::someCharity();
        $charity->setName("Charity Name");

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($charity);
        $em->flush();

        $charityId = $charity->getId(); // ID is set by the ORM, so we can't hard-code it here.

        $em->clear(); // forces loading a fresh copy from the DB when we call find.

        // act
        $charityReturnedFromDB = $sut->find($charityId);
        assert($charityReturnedFromDB instanceof Charity);

        // assert
        $this->assertNotSame($charity, $charityReturnedFromDB); // proves that we loaded a new copy of the charity out of the DB.
        $this->assertSame("Charity Name", $charity->getName());
    }
}
