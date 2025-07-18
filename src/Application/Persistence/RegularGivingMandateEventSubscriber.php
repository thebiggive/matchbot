<?php

namespace MatchBot\Application\Persistence;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\RegularGivingMandate;
use Psr\Container\ContainerInterface;
use Stripe\Mandate;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class RegularGivingMandateEventSubscriber implements EventSubscriber
{
    private ?RoutableMessageBus $bus = null;
    private ?DonorAccountRepository $donorRepository = null;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * Accepts whole container to lazy-load properties on first use, and allow for a circular dependency - this depends
     * on a repository which depends on the EntityManager which depends on this.
     */
    public function __construct(private ContainerInterface $container)
    {
    }

    #[\Override]
    public function getSubscribedEvents()
    {
        return [
            Events::postPersist,
            Events::postUpdate,
        ];
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - called by Doctrine ORM
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handlePostPersistOrUpdate($args);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - called by Doctrine ORM
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handlePostPersistOrUpdate($args);
    }

    public function handlePostPersistOrUpdate(PostPersistEventArgs|PostUpdateEventArgs $args): void
    {
        $this->donorRepository ??= $this->container->get(DonorAccountRepository::class);
        $this->bus ??= $this->container->get(RoutableMessageBus::class);

        $object = $args->getObject();

        if (!$object instanceof RegularGivingMandate) {
            return;
        }
        $mandate = $object;

        $donor = $this->donorRepository->findByPersonId($mandate->donorId());

        Assertion::notNull($donor, 'Donor not found on attempt to handle persisted mandate');

        // 3s delay when Active to reduce SF record access issues around activation time, when we typically
        // push for Create then Update in fairly quick succession.
        $stamps = $mandate->getStatus()->apiName() === Mandate::STATUS_ACTIVE ? [new DelayStamp(3_000)] : [];
        $this->bus->dispatch(new Envelope(MandateUpserted::fromMandate($mandate, $donor), $stamps));
    }
}
