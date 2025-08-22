<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Fees;

use MatchBot\Application\Fees\Calculator;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\Country;
use MatchBot\Tests\TestCase;
use PrinsFrank\Standards\Country\CountryAlpha2;

/**
 * Testing the fee calculator using the specific worked examples shown at https://biggive.org/our-fees/, currently
 * using examples from draft new version of that page.
 *
 * Some Psalm issues are suppressed here to make avoid verbosity and hopefully make the worked examples easy to edit.
 *
 * @psalm-suppress MixedReturnStatement
 */
class WebsiteExamplesCalculatorTest extends TestCase
{
    /**
     * The following worked examples should match and include everything at https://biggive.org/our-fees/ . All amounts
     * are in GBP.
     */
    public const array WORKED_EXAMPLES = [
        'Donation made with UK Visa Card, without gift aid' => [
            'donation_amount' => '10',
            'country' => 'United_Kingdom',
            'card_brand' => 'VISA',
            'with_gift_aid' => false,
            'processing_fee' => '0.35',
            'fee_vat' => '0.07',
            //-------------------//
            'total_fee' => '0.42',
            'total_transferred_to_charity' => '9.58',
        ],
//
        'Donation made with UK Visa Card, with gift aid' => [
            'donation_amount' => '10',
            'country' => 'United_Kingdom',
            'card_brand' => 'VISA',
            'processing_fee' => '0.35',
            'with_gift_aid' => true,
            'gift_aid_fee' => '0.08',
            'processing_fee_subtotal' => '0.43',
            'fee_vat' => '0.09',
            //-------------------//
            'total_fee' => '0.52',
            'total_transferred_to_charity' => '11.98',
        ],
//
        'Donation made with American Express Card from any country, without Gift Aid' => [
            'donation_amount' => '10',
            'country' => 'France',
            'card_brand' => 'AMEX',
            'processing_fee' => '0.52',
            'with_gift_aid' => false,
            'fee_vat' => '0.10',
            //-------------------//
            'total_fee' => '0.62',
            'total_transferred_to_charity' => '9.38',
        ],
        //
        'Donation made with Brazilian Visa Card, without Gift Aid' => [
            'donation_amount' => '10',
            'country' => 'Brazil',
            'card_brand' => 'Visa',
            'processing_fee' => '0.52',
            'with_gift_aid' => false,
            'fee_vat' => '0.10',
            //-------------------//
            'total_fee' => '0.62',
            'total_transferred_to_charity' => '9.38',
        ],
    ];

    /**
     * @return array<string, array<array>>
     */
    public function getFeeWorkedExamples(): array // @phpstan-ignore missingType.iterableValue
    {
        return array_map(fn(array $args) => [$args], self::WORKED_EXAMPLES);
    }


    /**
     * @dataProvider getFeeWorkedExamples
     * @psalm-suppress ArgumentTypeCoercion
     * @param array{
     *     card_brand: string,
     *     country: string,
     *     donation_amount: string,
     *     with_gift_aid: boolean,
     *     processing_fee: string,
     *     gift_aid_fee?: string,
     *     total_fee: string,
     *     fee_vat: string,
     *     processing_fee_subtotal: string,
     *     total_transferred_to_charity: string
     * } $args
     */
    public function testItCalculatesFeesAsShownOnWebsite(array $args): void
    {
        extract($args);

        $cardBrand = CardBrand::from(strtolower($card_brand));
        $cardCountry = $this->countryFromName($country);

        $fees = Calculator::calculate(
            psp: 'stripe',
            cardBrand: $cardBrand,
            cardCountry: $cardCountry,
            amount: $donation_amount,
            currencyCode:'GBP',
            hasGiftAid: $with_gift_aid
        );

        if ($with_gift_aid) {
            $gift_aid_amount = bcmul($donation_amount, '0.25', 2);
        } else {
            $gift_aid_amount = '0';
        }


        if (isset($gift_aid_fee)) {
            $this->assertSame($processing_fee_subtotal, bcadd($gift_aid_fee, $processing_fee, 2));
        } else {
            $processing_fee_subtotal = $processing_fee;
        }


        $this->assertSame($processing_fee_subtotal, $fees->coreFee);
        $this->assertSame($fee_vat, $fees->feeVat);
        $this->assertSame($total_fee, bcadd($fees->feeVat, $fees->coreFee, 2));


        $this->assertSame(
            $total_transferred_to_charity,
            bcadd(
                $gift_aid_amount,
                bcsub($donation_amount, $total_fee, 2),
                2
            )
        );
    }

    private function countryFromName(string $name): Country
    {
        $names = [];

        foreach (CountryAlpha2::cases() as $alpha2) {
            if ($alpha2->name === $name) {
                return Country::fromEnum($alpha2);
            }
            $names[] = $alpha2->name;
        }

        throw new \Exception("Invalid country name '$name', known countries are: " . implode(", ", $names));
    }
}
