<?php

namespace MatchBot\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
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
        $request = new Request(
            method: 'POST',
            uri: $this->apiPrefix . 'payment-sessions',
            headers: $this->headers($ryftAccountId),
            body: json_encode(
                [
                'amount' => $amount->amountInPence(),
                'currency' => $amount->currency->isoCode(),
                'captureFlow' => 'Manual', // should allow setting platform fee when we capture from matchbot code later.
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

    /**
     * See https://api-reference.ryftpay.com/#tag/Payments/operation/paymentSessionGet
     *
     * @return array{
     *     id: string,
     *     paymentMethod: array{
     *       card: array{
     *          scheme: string,
     *          binDetails: array{issuerCountry: string}
     *     }
     *   }
     * }
     */
    public function fetchPaymentSession(RyftAccountId $ryftAccountId, string $ryftPaymentSessionId): array
    {
        $request = new Request(
            method: 'GET',
            uri: $this->apiPrefix . 'payment-sessions/' . $ryftPaymentSessionId,
            headers: $this->headers($ryftAccountId),
        );

        $response = $this->client->send($request);

        $responseContents = $response->getBody()->getContents();
        $responseData = json_decode($responseContents, true, \JSON_THROW_ON_ERROR);
        $cardBrand = $responseData['paymentMethod']['card']['scheme']; // @phpstan-ignore-line
        $cardCountryIso2 = $responseData['paymentMethod']['card']['binDetails']['issuerCountry']; // @phpstan-ignore-line

        return $responseData;
    }


    /**
     * see https://api-reference.ryftpay.com/#tag/Payments/operation/paymentSessionCaptureById
     *
     * @param array{id: string, ...} $paymentSession
     * @return array{id: string, amount: int, platformFee: int, currency: string, status: string, ...}
     */
    public function capturePayment(RyftAccountId $ryftAccountId, array $paymentSession, Money $platformFee): array
    {
        $request = new Request(
            method: 'POST',
            uri: $this->apiPrefix . 'payment-sessions/' . $paymentSession['id'] . '/captures',
            headers: $this->headers($ryftAccountId),
            body: json_encode(
                [
                // ammount not set hereto capture full amount
                    'platformFee' => $platformFee->amountInPence(),
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        try {
            $response = $this->client->send($request);
        } catch (ClientException $clientException) {
            $message = $clientException->getResponse()->getBody()->getContents();
            throw new \Exception('Could not capture Ryft payment' . $message);
        } catch (GuzzleException $clientException) {
            $message = 'Failed to capture Ryft payment: ' . $clientException->getMessage();
            throw new \Exception('Could not capture Ryft payment' . $message);
        }

        $responseContents = $response->getBody()->getContents();

        /** @var array{id: string, amount: int, platformFee: int, currency: string, status: string} $responseData */
        $responseData = json_decode($responseContents, true, \JSON_THROW_ON_ERROR);

        $this->log->info('Captured Ryft payment or ' . $responseData['amount'] . ' for payment session ' . $responseData['id']);

        $status = $responseData['status'];
        if ($status !== 'Succeeded') {
            throw new \Exception('Could not capture Ryft payment');
        }

        return $responseData;
    }

    /**
     * @return array<string,string>
     */
    private function headers(RyftAccountId $ryftAccountId): array
    {
        return [
            'Authorization' => $this->secretKey,
            'Account' => $ryftAccountId->ryftAccountId,
        ];
    }
}
