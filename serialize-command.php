#!/usr/bin/env php
<?php
// phpcs:ignoreFile

namespace MatchBot {
// serializes matchbot CLI command to prepare it to send via SQS

    use MatchBot\Application\Messenger\CommandRequest;
    use Symfony\Component\Messenger\Envelope;

    $command = trim(fgets(STDIN));

    $envelope = new Envelope(new CommandRequest($command));

    echo addslashes(\serialize($envelope));
    echo "\n";
}

namespace MatchBot\Application\Messenger {
    // minimal copy of the class to allow serializing without composer installing anything.
    class CommandRequest
    {
        public function __construct(public string $command)
        {
        }
    }
}

namespace Symfony\Component\Messenger {
    // minimal copy of the class to allow serializing without composer installing anything.
    final class Envelope
    {
        /**
         * @var array<class-string<StampInterface>, list<StampInterface>>
         *
         * No classes for stamps present in this file, so we can't apply ay stamps here and this copy of
         * the constructor does not allow it.
         */
        private array $stamps = [];
        private object $message;

        /**
         * @param object|Envelope $message
         * @param StampInterface[] $stamps
         */
        public function __construct(object $message)
        {
            $this->message = $message;
        }
    }
}
