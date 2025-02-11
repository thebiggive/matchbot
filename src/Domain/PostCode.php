<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;

readonly class PostCode
{
    /**
     * Originally Based on the simplified pattern suggestions in https://stackoverflow.com/a/51885364/2803757
     * Copied from donate-frontend to matchbot.
     */
    public const string UK_VALIDATION_REGEX = '/^([A-Z][A-HJ-Y]?\d[A-Z\d]? \d[A-Z]{2}|GIR 0A{2})$/';
    public string $value;

    private function __construct(string $value, bool $skipUkSpecificValidation)
    {
        $value = mb_strtoUpper($value);

        if (! $skipUkSpecificValidation) {
            Assertion::notEmpty($value);

            Assertion::regex($value, self::UK_VALIDATION_REGEX);
        }

        $this->value = $value;
    }

    public static function of(string $value, bool $skipUKSpecificValidation = false): self
    {
        return new self($value, $skipUKSpecificValidation);
    }
}
