<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Factory as LockFactory;

abstract class LockingCommand extends Command
{
    /** @var LockFactory */
    private $lockFactory;
    /** @var \Symfony\Component\Lock\Lock */
    private $lock;

    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->start($output);
        if ($this->getLock()) {
            $this->doExecute($output);
            $this->releaseLock();
        } else {
            $output->writeln($this->getName() . ' did nothing as another instance had the lock.');
        }
        $this->finish($output);
    }

    private function getLock(): bool
    {
        $this->lock = $this->lockFactory->createLock(
            $this->getName(),
            30 * 60,    // 30 minute lock
            true        // auto-release on process end, inc. crashes
        );

        return $this->lock->acquire(false);
    }

    private function releaseLock(): void
    {
        $this->lock->release();
    }
}
