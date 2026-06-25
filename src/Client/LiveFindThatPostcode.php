<?php

declare(strict_types=1);
namespace MatchBot\Client;

use BcMath\Number;
use MatchBot\Application\Assertion;
use MatchBot\Domain\PostCode;
use Override;

/**
 * Client for calling Find That Postcode. Returns a much simpler data structure than FTP itself.
 *
 * Contains many static analysis issue suppressions, which is hard to avoid when dealing with a complex external
 * data source without a schema that static analysis tools will understand.
 */
class LiveFindThatPostcode extends Common implements FindThatPostcode
{
    /** @var list<string>
     * Prefixes used by ONS for region codes that we are interested in, ordered from most specific to least specific.
     * Based on table at  https://en.wikipedia.org/wiki/GSS_coding_system
     */
    public const array REGION_PREFIXES = [
        'E05', // England Ward or Electoral Division
        'W05', // Wales Ward or Electoral Division
        'S13', // Scotland Ward or Electoral Division
        'N08', // Northern Ireland Ward or Electoral Division
        'E06', // England Unitary Authority
        'E09', // London Borough
        'E06', // England Unitary Authority
        'E12', // English Region
        'E92', // England Country
        'W92', // Wales Country
        'S92', // Scotland Country
        'N92', // Northern Ireland Country
    ];

    /**
     * @inheritDoc
     * @mago-expect analysis:mixed-assignment
     */
    #[Override]
    public function getDataOnPostcode(PostCode $postcode): array
    {
        $uri = 'https://findthatpostcode.uk/postcodes/' . \urlencode($postcode->value) . '.json';

        $response = $this->getHttpClient()->request('GET', $uri);

        $body = $response->getBody()->getContents();

        $decoded = \json_decode($body, flags: \JSON_THROW_ON_ERROR, associative: true);

        Assertion::isArray($decoded);

        return self::parseFindThatPostcodeResponse($decoded);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getDataOnPoint(Number $lattitude, Number $longitude): array
    {
        // TODO: Implement getDataOnPoint() method.
        return [];
    }

    /**
     * Takes the data as decoded from the Find That Postcode response, and returns a much simpler data structure
     * suitable for use within Matchbot.
     *
     * @mago-expect analysis:less-specific-nested-return-statement
     * @mago-expect analysis:mixed-assignment
     * @mago-expect analysis:mixed-array-access
     * @mago-expect analysis:invalid-return-statement
     * @mago-expect analysis:mixed-argument
     * @mago-expect analysis:possibly-false-operand
     *
     * @psalm-suppress MixedReturnTypeCoercion
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress MixedArgument
     *
     * @psalm-suppress UnusedVariable - this looks like a psalm bug - afaik the $name variable is used.
     *
     * @param array<array-key, mixed> $jsonData
     *
     *
     * @return list<array{name: string, code: string}>
     *
     *
     */
    public static function parseFindThatPostcodeResponse(array $jsonData): array
    {
        $areasToReturn  = [];

        $included = $jsonData['included'];
        Assertion::isArray($included);

        foreach ($included as $include) {
            $code = $include['attributes']['code'] ?? null; // @phpstan-ignore offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible
            $name = $include['attributes']['name']; // @phpstan-ignore offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible

            if ($code === null) {
                continue;
            }

            $areasToReturn[] = ['name' => $name, 'code' => $code];
        }

        // filter out areas that do not have three letter prefixes of interest to us.
        $areasToReturn = $areasToReturn
            |> (static fn(array $areas): array => \array_filter($areas, callback: static fn (array $area) => \in_array(needle: \mb_substr($area['code'], 0, 3), haystack: self::REGION_PREFIXES, strict: true)))
            ;

        // sort by specificity of region.
        usort($areasToReturn, static fn(array $a, array $b) =>
            \array_search(\mb_substr($a['code'], 0, 3), self::REGION_PREFIXES, strict: true) <=> \array_search(\mb_substr($b['code'], 0, 3), self::REGION_PREFIXES, strict: true));

        // remove duplicates
        return self::uniqueMultidimArray($areasToReturn, 'code'); // not sure why there are duplicate values in the data I pulled from FTP.
    }


    /**
     * Based on comment at https://www.php.net/manual/en/function.array-unique.php by
     * Ghanshyam Katriya
     *
     * @psalm-suppress MixedAssignment
     *
     * @template T of array<mixed>
     * @param array<T> $array
     * @param string $key
     *
     * @return list<T>
     */
    private static function uniqueMultidimArray($array, $key)
    {
        $temp_array = [];
        $i = 0;
        $key_array = [];

        foreach ($array as $val) {
            if (!in_array($val[$key], $key_array, strict: true)) {
                $key_array[$i] = $val[$key];
                $temp_array[$i] = $val;
            }
            $i++;
        }

        return array_values($temp_array);
    }
}
