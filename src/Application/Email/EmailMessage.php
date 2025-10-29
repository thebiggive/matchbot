<?php

namespace MatchBot\Application\Email;

use MatchBot\Domain\EmailAddress;

/**
 * A message to be sent by email, via our Mailer service
 *
 * @psalm-type emailParams array<string,string|null|int|float|bool|array<array-key, mixed>>
 */
readonly class EmailMessage
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
    public static function donorRegularDonationFailed(EmailAddress $emailAddress, array $params): self
    {
        return new self('donor-regular-donation-failed-payment', $emailAddress, $params);
    }

    /**
     * @param emailParams $params
     */
    public static function donorDonationSuccess(EmailAddress $emailAddress, array $params): self
    {
        return new self('donor-donation-success', $emailAddress, $params);
    }

    public function withToAddress(EmailAddress $to): self
    {
        return new self(
            templateKey: $this->templateKey,
            emailAddress: $to,
            params: $this->params
        );
    }
}
