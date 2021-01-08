<?php

namespace MatchBot\Application\Persistence;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use Psr\Log\LoggerInterface;

/**
 * Adapted from @link https://medium.com/lebouchondigital/thread-safe-business-logic-with-doctrine-f09c633f6554
 * and Mike Litoris's comment on the same.
 */
class RetrySafeEntityManager extends EntityManagerDecorator
{
    private EntityManagerInterface $entityManager;
    /**
     * @var int For non-matching updates that always use Doctrine, maximum number of times to try again when
     *          Doctrine reports that the error is recoverable and that retrying makes sense
     */
    private int $maxLockRetries = 3;

    public function __construct(
        private ORM\Configuration $ormConfig,
        private array $connectionSettings,
        private LoggerInterface $logger,
    ) {
        $this->entityManager = $this->buildEntityManager();
        parent::__construct($this->entityManager);
    }

    #[Pure]
    public function transactional($callback)
    {
        $retries = 0;
        do {
            $this->beginTransaction();

            try {
                $ret = $callback();

                $this->flush();
                $this->commit();

                return $ret;
            } catch (RetryableException $ex) {
                $this->rollback();
                $this->close();

                $this->logger->warning('RetrySafeEntityManager rolling back from ' . get_class($ex));
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

    public function resetManager(): void
    {
        $this->entityManager = $this->buildEntityManager();
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        return EntityManager::create($this->connectionSettings, $this->ormConfig);
    }
}
