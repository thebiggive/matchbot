<?php

namespace MatchBot\Client;

use BcMath\Number;
use MatchBot\Application\Assertion;
use MatchBot\Client\FindThatPostcode;
use MatchBot\Domain\PostCode;
use Override;

class LiveFindThatPostcode extends Common implements FindThatPostcode
{
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
     *
     * @psalm-suppress MixedReturnTypeCoercion
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
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

        return $areasToReturn;
    }
}
