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
use MatchBot\Domain\RyftPaymentSessionId;
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
     *
     *
     * @return array{id: RyftPaymentSessionId, clientSecret: string} . Note that the Client Secret must not
     * be logged or saved - it is only for sending to the client.
     *
     * See https://api-reference.ryftpay.com/#tag/Payments/operation/paymentSessionCreate
     */
    public function createPaymentSession(RyftAccountId $ryftAccountId, Money $amount): array
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

        $response = json_decode($responseContents, associative: true, flags: \JSON_THROW_ON_ERROR);
        Assertion::isArray($response);
        $clientSecret = $response['clientSecret'];
        $id = $response['id'];

        \assert(\is_string($clientSecret));
        \assert(\is_string($id));

        return [
            'id' => RyftPaymentSessionId::of($id),
            'clientSecret' => $clientSecret
        ];
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
        $responseData = json_decode($responseContents, associative: true, flags: \JSON_THROW_ON_ERROR);
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
                flags: \JSON_THROW_ON_ERROR,
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
        $responseData = json_decode($responseContents, associative: true, flags: \JSON_THROW_ON_ERROR);

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
