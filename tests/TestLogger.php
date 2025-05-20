<?php

namespace MatchBot\Tests;

use Psr\Log\AbstractLogger;

/**
 * In memory logger for use in tests. All messages are added to the public $messages property.
 */
class TestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}>  */
    public array $messages = [];

    public string $logString = "";

    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        $this->logString .= "$level: " . (string) $message . "\n"; // @phpstan-ignore encapsedStringPart.nonString
    }
}
