<?php

namespace Domain;

use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\PostCode;
use MatchBot\Tests\TestCase;

class PostcodeTest extends TestCase {

    /**
     * @dataProvider ukPostcodes
     */
    public function testItAcceptsUKPostcodes(string $inputPostcode): void
    {
        $postcode = Postcode::of($inputPostcode, false);

        $this->assertEqualsIgnoringCase($inputPostcode, $postcode->value);
    }

    /**
     * @dataProvider ukInvalidPostcodes
     */
    public function testItRejectsUKInvalidPostcodes(string $inputPostcode): void
    {
        $this->expectException(AssertionFailedException::class);
        Postcode::of($inputPostcode, false);
    }

    /**
     * @return list<array{0: string}>
     */
    public function ukPostcodes(): array
    {
        return [
            ['WC2B 5LX'],
            ['wc2b 5lx'], // lowecase is converted to upper in constructor
            ['N1 1AA'],
            // random postcodes from https://www.doogal.co.uk/PostcodeGenerator
            ['AB38 9QS'],
            ['CF36 5TR'],
            ['LL14 1NF'],
            ['DY5 2AQ'],
            ['CO11 2GJ'],
            ['MK11 4AN'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public function ukInvalidPostcodes(): array
    {
        return [
            [''],
            ['a'],
            ['WC2B5LX'], // missing space
            ['WC2B5L'], // missing last character
            ['1'],
            ['📮'],
        ];
    }
}