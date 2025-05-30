<?php

namespace MatchBot\Domain;

use JsonSerializable;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;

/**
 * 18 digit ID as used at salesforce. The casing will be normalised on construction to be acceptable to salesforce.
 *
 * @psalm-template-covariant T of SalesforceProxy
 */
class Salesforce18Id implements JsonSerializable
{
    public readonly string $value;

    private const string CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    /**
     * @param string $value
     * @psalm-param class-string<T> $_entityClass
     */
    private function __construct(string $value, ?string $_entityClass)
    {
        Assertion::length($value, 18, self::lengthErrorMessage(...));

        Assertion::regex(
            $value,
            '/[a-zA-Z0-9]{18}/',
            static fn(array $args) => "{$args['value']} does not match pattern for a Salesforce ID"
        );

        $caseCorrected = self::correctCase($value);

        Assertion::same(\strtolower($caseCorrected), \strtolower($value));

        $this->value = $caseCorrected;
    }

    /**
     * @throws AssertionFailedException
     * @return self<SalesforceProxy>
     */
    public static function of(string $value): self
    {
        // I think we could also validate a checksum here but as the IDs are generally not hand typed that won't get us
        // much.

        return new self($value, null);
    }

    /**
     * @return self<Charity>
     */
    public static function ofCharity(string $id): self
    {
        return new self($id, Charity::class);
    }

    /**
     * @return self<Fund>
     */
    public static function ofFund(string $id): self
    {
        return new self($id, Fund::class);
    }

    /**
     * @return self<Campaign>
     */
    public static function ofCampaign(string $id): self
    {
        return new self($id, Campaign::class);
    }

    /**
     * @return self<RegularGivingMandate>
     */
    public static function ofRegularGivingMandate(string $id)
    {
        return new self($id, RegularGivingMandate::class);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }


    /**
     * @param array{length: int, value: string} $args
     */
    private static function lengthErrorMessage(array $args): string
    {
        return "Salesforce ID should have {$args['length']} chars, '{$args['value']}' has " . strlen($args['value']);
    }

    /**
     * Function implementation by Daniel Ballinger, Adrian Larson,
     * and shadowhand at https://salesforce.stackexchange.com
     *
     * (Daniel wrote in C#, Adrian ported to Apex, shadowhand ported that to PHP)
     *
     * https://salesforce.stackexchange.com/a/388044/145088
     * https://salesforce.stackexchange.com/users/123722/shadowhand
     * CC Licensed Attribution-ShareAlike 4.0 International
     */
    private function correctCase(string $input): string
    {
        assert(assertion: strlen($input) === 18);

        $id = '';

        $capitalize = [
            ...self::shouldCapitalize(letter: substr($input, offset: 15, length: 1)),
            ...self::shouldCapitalize(letter: substr($input, offset: 16, length: 1)),
            ...self::shouldCapitalize(letter: substr($input, offset: 17, length: 1)),
        ];

        for ($i = 0; $i < 15; $i++) {
            $letter = substr($input, offset: $i, length: 1);

            $id .= $capitalize[$i] ? strtoupper($letter) : strtolower($letter);
        }

        $id .= strtoupper(substr($input, offset: 15, length: 3));

        return $id;
    }

    /**
     * Function implementation by Daniel Ballinger, Adrian Larson,
     * and shadowhand at https://salesforce.stackexchange.com
     *
     * (Daniel wrote in C#, Adrian ported to Apex, shadowhand ported that to PHP)
     *
     * https://salesforce.stackexchange.com/a/388044/145088
     * https://salesforce.stackexchange.com/users/123722/shadowhand
     * CC Licensed Attribution-ShareAlike 4.0 International
     *
     * @psalm-suppress PossiblyFalseOperand
     * @return list<bool>
     */
    private static function shouldCapitalize(string $letter): array
    {
        $index = strpos(haystack: self::CHARS, needle: strtoupper($letter));
        $result = [];

        for ($bit = 0; $bit < 5; $bit++) {
            $result[] = ($index & (1 << $bit)) !== 0;
        }

        return $result;
    }
}
