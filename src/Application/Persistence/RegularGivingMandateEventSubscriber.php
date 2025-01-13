<?php

namespace MatchBot\Application\Persistence;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Domain\RegularGivingMandate;
use Stripe\Mandate;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

class RegularGivingMandateEventSubscriber implements EventSubscriber
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(private RoutableMessageBus $bus)
    {
    }

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
        $object = $args->getObject();

        if (!$object instanceof RegularGivingMandate) {
            return;
        }
        $mandate = $object;

        $this->bus->dispatch(new Envelope(MandateUpserted::fromMandate($mandate)));
    }
}
