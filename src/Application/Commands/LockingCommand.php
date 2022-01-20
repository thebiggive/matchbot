<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Base class for Commands which should be protected against overlapping runs of the same Command
 * name, for 30 minutes.
 */
abstract class LockingCommand extends Command
{
    private LockFactory $lockFactory;
    private LockInterface $lock;
    private LoggerInterface $logger;

    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->start($output);
        if ($this->getLock()) {
            $return = $this->doExecute($input, $output);
            $this->releaseLock();
        } else {
            $message = $this->getName() . ' did nothing as another instance had the lock.';
            $output->writeln($message);
            $this->logger->warning($message);
            return 0; // Log at warning level to help monitoring volume but don't fire error alarms.
        }
        $this->finish($output);

        return $return;
    }

    private function getLock(): bool
    {
        $this->lock = $this->lockFactory->createLock(
            $this->getName(),
            30 * 60,    // 30 minute lock
            true        // auto-release on process end
        );

        return $this->lock->acquire(false);
    }

    private function releaseLock(): void
    {
        $this->lock->release();
    }
}
