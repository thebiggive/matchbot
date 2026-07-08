<?php

namespace MatchBot\Application\Messenger;

/**
 * A queued request to invoke the Console app with the given command (and any arguments).
 */
class CommandRequest
{
    public function __construct(public string $command)
    {
    }
}
