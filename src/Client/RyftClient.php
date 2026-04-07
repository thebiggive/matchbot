<?php

namespace MatchBot\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\Money;
use MatchBot\Domain\RyftAccountId;
use Psr\Log\LoggerInterface;

/**
 * Using custom client just to call the functions of the Ryft HTTP API that are useful to us
 * rather than the Ryft PHP SDK as that doesn't seem to add any real value - it isn't usable
 * without referring in detail to documentation provided separately.
 */
class RyftClient
{
    private string $apiPrefix;

    public function __construct(
        private string $publicKey, // @phpstan-ignore property.onlyWritten
        private string $secretKey,
        private Client $client,
        Environment $environment,
        private LoggerInterface $log,
    ) {
        $this->apiPrefix = match ($environment) {
            Environment::Production => 'https://api.ryftpay.com/v1/',
            Environment::Staging, Environment::Local, Environment::Regression => 'https://sandbox-api.ryftpay.com/v1/',
            Environment::Test => 'https://placeholder-for-ryft-api-in-test.biggive.org/v1/'
        };
    }

    /**
     * @param RyftAccountId $ryftAccountId Ryft sub-account ID of the charity that will receive the payment
     * @return string client secret
     *
     * See https://api-reference.ryftpay.com/#tag/Payments/operation/paymentSessionCreate
     */
    public function createPaymentSession(RyftAccountId $ryftAccountId, Money $amount): string
    {
        $headers = [
            'Authorization' => $this->secretKey,
            'Account' => $ryftAccountId->ryftAccountId,
        ];

        $request = new Request(
            method: 'POST',
            uri: $this->apiPrefix . 'payment-sessions',
            headers: $headers,
            body: json_encode(
                [
                'amount' => $amount->amountInPence(),
                'currency' => $amount->currency->isoCode(),

                ],
                \JSON_THROW_ON_ERROR
            )
        );

        $response = $this->client->send($request);

        $responseContents = $response->getBody()->getContents();

        $this->log->info($responseContents);

        /*
         * 2026-04-01T15:40:22.243849016Z [2026-04-01T15:40:22.243429+00:00] matchbot.INFO: {"id":"ps_01KN4V7VRAGFBGHGRXDZBVTPFM","amount":270,"currency":"GBP","paymentType":"Standard","entryMode":"Online","enabledPaymentMethods":["Card"],"returnUrl":"https://donate.biggive.org","status":"PendingPayment","refundedAmount":0,"clientSecret":"ps_01KN4V7VRAGFBGHGRXDZBVTPFM_secret_0a31276f-1e46-40b0-8bdb-75742249d0b4","statementDescriptor":{"descriptor":"Big Give","city":"Manchester"},"authorizationType":"FinalAuth","captureFlow":"Automatic","createdTimestamp":1775058022,"lastUpdatedTimestamp":1775058022} [] {"commit":"fde1a7d","uid":"9425ebd","memory_peak_usage":"4 MB"}

         */

        $response = json_decode($responseContents, true, \JSON_THROW_ON_ERROR);
        Assertion::isArray($response);
        $clientSecret = $response['clientSecret'];

        \assert(\is_string($clientSecret));

        return $clientSecret;
    }
}
