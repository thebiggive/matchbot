<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Messenger\EmailRequest;
use MatchBot\Client\Mailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * We now enqueue via SQS within MatchBot primarily because the cleanest way to do networking between PHP apps relies
 * on ECS Service Connect, and some scheduled MatchBot work is in tasks that don't have a Service.
 */
#[AsMessageHandler]
readonly class EmailRequestHandler
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function __invoke(EmailRequest $message): void
    {
        $this->mailer->send($message->emailMessage);
    }
}
