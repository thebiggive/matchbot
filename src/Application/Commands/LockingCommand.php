<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

abstract class LockingCommand extends Command
{
    private LockFactory $lockFactory;
    private LockInterface $lock;

    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->start($output);
        if ($this->getLock()) {
            $return = $this->doExecute($input, $output);
            $this->releaseLock();
        } else {
            $output->writeln($this->getName() . ' did nothing as another instance had the lock.');
            return 10;
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
