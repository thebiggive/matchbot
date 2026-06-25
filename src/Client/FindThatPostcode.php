<?php

namespace MatchBot\Client;

use BcMath\Number;
use MatchBot\Domain\PostCode;

interface FindThatPostcode
{
    /**
     * Given a postcode, returns a list of ONS region details for the regions of the UK that contain this postcode, in
     * order from most specific to least specific.
     *
     * E.g. for a postcode of N4 1UJ it would return
     * [{},['name' => 'Haringey', code' => 'E09000014'],{},]
     *
     * E.g. for
     *
     * @param PostCode $postcode
     * @return list<array{'code': string, 'name': string}>
     *
     * @psalm-suppress PossiblyUnusedMethod - to use soon
     */
    public function getDataOnPostcode(PostCode $postcode): array;

    /**
     * @return list<array{'code': string, 'name': string}>
     *
     */
    public function getDataOnPoint(Number $lattitude, Number $longitude): array;
}
