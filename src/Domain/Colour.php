<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

/**
 * Represents a 24 bit colour in the sRGB colour space.
 */
readonly class Colour
{
    private string $hexCode;

    private function __construct(
        string $hexCode
    ) {
        $this->hexCode = \strtoupper($hexCode);
        Assertion::regex($this->hexCode, '/^#[A-F0-9]{6}$/', 'Hex color code required');
    }

    public static function fromHex(string $hexCode): self
    {
        return new self($hexCode);
    }

    /**
     * @return string hex colour code prefixed with #, e.g. '#B30510'
     */
    public function toHex(): string
    {
        return $this->hexCode;
    }

    public function __toString(): string
    {
        return $this->hexCode;
    }
}
