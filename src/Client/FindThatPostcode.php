<?php

namespace MatchBot\Client;

use BcMath\Number;
use MatchBot\Domain\PostCode;

interface FindThatPostcode {

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
     * @return list<string>
     */
    public function getDataOnPostcode(Postcode $postcode): array;

    /**
     * @return @return list<string>
     */
    public function getDataOnPoint(Number $lattitude, Number $longitude): array;
}
