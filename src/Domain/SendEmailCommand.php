<?php

namespace MatchBot\Domain;

/**
 * @psalm-type emailParams array<string,string|null|int|float|array>
 */
readonly class SendEmailCommand
{
    /**
     * @param emailParams $params
     */
    private function __construct(
        public string $templateKey,
        public EmailAddress $emailAddress,
        public array $params,
    ) {
    }

    /**
     * @param emailParams $params
     */
    public static function donorMandateConfirmation(EmailAddress $emailAddress, array $params): self
    {
        return new self('donor-mandate-confirmation', $emailAddress, $params);
    }

    /**
     * @param emailParams $params
     */
    public static function donorDonationSuccess(EmailAddress $emailAddress, array $params): self
    {
        return new self('donor-donation-success', $emailAddress, $params);
    }
}
