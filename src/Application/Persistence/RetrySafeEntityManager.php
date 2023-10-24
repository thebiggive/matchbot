<?php

declare(strict_types=1);

namespace MatchBot\Application\Persistence;

use closure;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\EntityManagerClosed;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\SalesforceReadProxy;
use Psr\Log\LoggerInterface;

/**
 * Adapted from @link https://medium.com/lebouchondigital/thread-safe-business-logic-with-doctrine-f09c633f6554
 * and Mike Litoris's comment on the same.
 */
class RetrySafeEntityManager extends EntityManagerDecorator
{
    private ORM\EntityManagerInterface $entityManager;

    /**
     * @var int For non-matching updates that always use Doctrine, maximum number of times to try again when
     *          Doctrine reports that the error is recoverable and that retrying makes sense
     */
    private int $maxLockRetries = 3;

    /**
     * Whenever an entity is persisted we keep a reference here in addition to letting the underlying EM
     * track it until flushed. If the underlying EM is closed and we need a new one we will persist all
     * entities via the new EM.
     *
     * Otherwise we would have some entities persisted into one EM, some in another, and the new EM then complains
     * about finding a new entity via a relationship.
     *
     * @var list<object>
     */
    private array $persistedEntitiesNotYetFlushed = [];

    /**
     * @var Closure():EntityManager
     */
    private closure $entityManagerFactory;

    public function __construct(
        private ORM\Configuration $ormConfig,
        private array $connectionSettings,
        private LoggerInterface $logger,
        closure $entityManagerFactory = null,
    ) {
        $this->entityManagerFactory = $entityManagerFactory ??
            fn (): EntityManager => EntityManager::create($this->connectionSettings, $this->ormConfig);

        $this->entityManager = $this->buildEntityManager();
        parent::__construct($this->entityManager);
    }

    /**
     * Used only in tests to let us control the behaviour of the underlying EM.
     * @param closure():ORM\EntityManagerInterface $entityManagerFactory
     * @param ORM\Configuration $ormConfig
     * @return self
     */
    public static function fromEntitymanagerInterface(closure $entityManagerFactory, \Doctrine\ORM\Configuration $ormConfig, LoggerInterface $logger): self
    {
        $that = new self($ormConfig, [], $logger);
        $that->entityManagerFactory = $entityManagerFactory;

        return $that;
    }

    /**
     * @template T
     * @psalm-param callable(): T $func
     * @psalm-return T
     */
    public function transactional($func): mixed
    {
        $retries = 0;
        do {
            $this->beginTransaction();

            try {
                $ret = $func();

                $this->flush();
                $this->commit();

                return $ret;
            } catch (RetryableException $ex) {
                $this->rollback();
                $this->close();

                $this->logger->error(
                    'EM closed. RetrySafeEntityManager::transactional rolling back from ' . get_class($ex) .
                    $ex->__tostring()
                );
                usleep(random_int(0, 200000)); // Wait between 0 and 0.2 seconds before retrying

                $this->resetManager();
                ++$retries;
            } catch (\Exception $ex) {
                $this->rollback();
                $this->logger->error(
                    'RetrySafeEntityManager bailing out having hit ' . get_class($ex) . ': ' . $ex->getMessage()
                );

                throw $ex;
            }
        } while ($retries < $this->maxLockRetries);

        $this->logger->error('RetrySafeEntityManager bailing out after ' . $this->maxLockRetries . ' tries');

        throw $ex;
    }

    /**
     * Attempt a persist the normal way, and if the underlying EM is closed, make a new one
     * and try a second time. We were forced to take this approach because the properties
     * tracking a closed EM are annotated private.
     * @param object $object
     * {@inheritDoc}
     */
    public function persist($object): void
    {
        $this->persistedEntitiesNotYetFlushed[] = $object;
        try {
            $this->entityManager->persist($object);
        } catch (EntityManagerClosed $closedException) {
            $this->logger->error(
                'EM closed. RetrySafeEntityManager::persist() trying with a new instance,' .
                $closedException->__tostring()
            );
            $this->resetManager();
        }
    }


    /**
     * @param object $object
     * @param 0|1|2|4|null  $lockMode
     * @see LockMode
     */
    public function refresh($object, ?int $lockMode = null): void
    {
        try {
            $this->entityManager->refresh($object, $lockMode);
        } catch (EntityManagerClosed $closedException) {
            $this->logger->error(
                'EM closed. RetrySafeEntityManager::refresh() trying with a new instance,' .
                $closedException->__tostring()
            );
            $this->resetManager();
            $this->entityManager->refresh($object, $lockMode);
        }
    }

    /**
     * Attempt a flush the normal way, and if the underlying EM is closed, make a new one
     * and try a second time. We were forced to take this approach because the properties
     * tracking a closed EM are annotated private.
     *
     * @param object|mixed[]|null $entity
     * {@inheritDoc}
     */
    public function flush($entity = null): void
    {
//        throw new \Exception("flushing");
        if ($entity) {
            $this->persistedEntitiesNotYetFlushed[] = $entity;
        }
        try {
            $this->entityManager->flush($entity);
            $this->persistedEntitiesNotYetFlushed = [];
        } catch (EntityManagerClosed $closedException) {
            $this->logger->error(
                'EM closed. RetrySafeEntityManager::flush() trying with a new instance,' .
                $closedException->__tostring()
            );
            $this->resetManager();
            $this->entityManager->flush($entity);
            $this->persistedEntitiesNotYetFlushed = [];
        }
    }

    public function resetManager(): void
    {
        $this->entityManager = $this->buildEntityManager();
        $this->rePersistAllEntititesToInternalEM();
    }

    /**
     * We need to override the base `EntityManager` call with the equivalent so that repositories
     * contain the retry-safe EM (i.e. `$this` in our current context) and not the default one.
     */
    public function getRepository($className)
    {
        return $this->ormConfig->getRepositoryFactory()->getRepository($this, $className);
    }

    /**
     * Currently just used for easier testing, to avoid needing a very complex mix of both reflection
     * and partial mocks.
     */
    public function setEntityManager(EntityManager $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    private function buildEntityManager(): ORM\EntityManagerInterface
    {
        $factory = $this->entityManagerFactory;
        return $factory();
    }

    /**
     * @return void
     * @throws ORM\Exception\ORMException
     */
    public function rePersistAllEntititesToInternalEM(): void
    {
        return;
        echo "\n\n";
        foreach ($this->persistedEntitiesNotYetFlushed as $entity) {
            if ($entity instanceof CampaignFunding) {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            }
       //     $this->entityManager->persist($entity);
        }
        echo "\n\n";
    }
}
