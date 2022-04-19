<?php

declare(strict_types=1);

namespace MatchBot\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;

class AwsTraceIdProcessor implements ProcessorInterface
{
    public function __invoke(array $record): array
    {
        $traceId = $_SERVER['HTTP_X_AMZN_TRACE_ID'] ?? null;
        if (!empty($traceId)) {
            $record['extra']['x-amzn-trace-id'] = $traceId;
        }

        return $record;
    }
}
