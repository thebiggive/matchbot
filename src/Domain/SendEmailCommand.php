<?php

namespace MatchBot\Domain;

readonly class SendEmailCommand
{
    /**
     * @param array<string,string|null|int|float|array> $params
     */
    private function __construct(
        public string $templateKey,
        public EmailAddress $emailAddress,
        public array $params,
    ) {
    }

    /**
     * @param array<string,string|null|int|float|array> $params
     */
    public static function donorMandateConfirmation(EmailAddress $emailAddress, array $params): self
    {
        return new self('donor-mandate-confirmation', $emailAddress, $params);
    }
}
