<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Application\Email\EmailMessage;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageDeduplicationAwareInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;
use Symfony\Component\Messenger\Envelope;

class EmailRequest implements MessageDeduplicationAwareInterface, MessageGroupAwareInterface
{
    /**
     * Only test should need to use the constructor directly, keeping public for now.
     */
    public function __construct(public EmailMessage $emailMessage)
    {
    }

    public static function fromMessageEnveloped(EmailMessage $emailMessage): Envelope
    {
        return new Envelope(new EmailRequest($emailMessage));
    }

    #[\Override]
    public function getMessageGroupId(): ?string
    {
        return 'email.request.' . $this->emailMessage->emailAddress->email;
    }

    #[\Override]
    public function getMessageDeduplicationId(): ?string
    {
        return $this->emailMessage->templateKey . '.' .
            $this->emailMessage->emailAddress->email . '.' .
            md5(json_encode($this->emailMessage->params, JSON_THROW_ON_ERROR));
    }
}
