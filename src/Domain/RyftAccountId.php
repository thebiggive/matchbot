<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * ID of an account for a charity at Ryft
 * See https://developer.ryftpay.com/documentation/api/reference/openapi/accounts
 *
 * @todo consider if we can / should replace this with something from the Ryft PHP SDK.
 */
readonly class RyftAccountId
{
    #[Column(type: 'string')]
    public string $ryftAccountId;

    private function __construct(
        string $ryftAccountId
    ) {
        $this->ryftAccountId = $ryftAccountId;
        Assertion::notEmpty($this->ryftAccountId);
        Assertion::maxLength($this->ryftAccountId, 255);

        // e.g. ac_b83f2653-06d7-44a9-a548-5825e8186004
        Assertion::regex(
            $this->ryftAccountId,
            '/^ac_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            "Given ryft account ID {$ryftAccountId} does not match expected pattern"
        );
    }

    /**
     * @param string $stripeID - must fit the pattern for a Ryft ID.
     */
    public static function of(string $stripeID): self
    {
        return new self($stripeID);
    }
}
