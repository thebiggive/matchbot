<?php

declare(strict_types=1);

namespace MatchBot\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;

class AwsTraceIdProcessor implements ProcessorInterface
{
    #[\Override] // @phpstan-ignore missingType.generics
    public function __invoke(array|\ArrayAccess $record): array
    {
        $traceId = $_SERVER['HTTP_X_AMZN_TRACE_ID'] ?? null;
        if (is_string($traceId)) {
            $record['extra']['x-amzn-trace-id'] = $traceId; // @phpstan-ignore offsetAccess.nonOffsetAccessible
        }

        return $record;
    }
}
