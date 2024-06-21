<?php

declare(strict_types=1);

namespace MatchBot\Application\Persistence;

use Closure;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
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
     * @var Closure():EntityManagerInterface
     */
    private Closure $entityManagerFactory;

    /**
     * @param Closure():\Doctrine\ORM\EntityManagerInterface $entityManagerFactory
     * @param array{
     *      driver: 'pdo_mysql',
     *      host: string,
     *      port: int,
     *      dbname: string,
     *      user: string,
     *      password: string,
     *      charset: string,
     *      defaultTableOptions: array{collate: string},
     *      driverOptions: array{1009: ?string}
     * } $connectionSettings
     */
    public function __construct(
        private ORM\Configuration $ormConfig,
        array $connectionSettings,
        private LoggerInterface $logger,
        \Closure $entityManagerFactory = null,
    ) {
        $this->entityManagerFactory = $entityManagerFactory ??
            fn (): EntityManager => new EntityManager(
                DriverManager::getConnection($connectionSettings),
                $this->ormConfig,
            );

        $this->entityManager = $this->buildEntityManager();
        parent::__construct($this->entityManager);
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
            try {
                $this->beginTransaction();

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
        try {
            $this->entityManager->persist($object);
        } catch (EntityManagerClosed $closedException) {
            $this->logger->error(
                'EM closed. RetrySafeEntityManager::persist() trying with a new instance,' .
                $closedException->__tostring()
            );
            $this->resetManager();
            $this->entityManager->persist($object);
        }
    }

    public function persistWithoutRetries(object $object): void
    {
        $this->entityManager->persist($object);
    }

    /**
     * @param object $object
     * @param 0|1|2|4|null  $lockMode
     * @see LockMode
     * @psalm-suppress ParamNameMismatch This seems to be impossible to fix rn because `ObjectManagerDecorator` and
     *                `EntityManagerInterface` disagree on the param name.
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
        try {
            $this->entityManager->flush($entity);
        } catch (EntityManagerClosed $closedException) {
            $this->logger->error(
                'EM closed. RetrySafeEntityManager::flush() trying with a new instance,' .
                $closedException->__tostring()
            );
            $this->resetManager();
            $this->entityManager->flush($entity);
        }
    }

    public function resetManager(): void
    {
        $this->entityManager = $this->buildEntityManager();
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
}
