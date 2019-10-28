<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use MatchBot\Domain\DonationRepository;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ProphecyInterface;

abstract class EntityTest extends TestCase
{
    /** @var ProphecyInterface|EntityManager */
    protected $em;

    public function setUp(): void
    {
        $connection = $this->prophesize(Connection::class)->reveal();

//        $connection = new Connection([], $this->prophesize(Driver::class)->reveal());
        /** @var ProphecyInterface|DonationRepository */

//        $donationRepoProphet = $this->prophesize(DonationRepository::class);

        /** @var ProphecyInterface|EntityManager */
//        $emProphet = $this->prophesize(EntityManager::class);
//        $emProphet->getRepository(DonationRepository::class)->willReturn($donationRepoProphet->reveal());
//        $emProphet->persist(Argument::any())->willReturn(null);
//        $emProphet->flush(Argument::any())->willReturn(null);

        $metadataDriver = new AnnotationDriver(new AnnotationReader());
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl($metadataDriver);
        $this->em = EntityManager::create($connection, $configuration);
    }
}
