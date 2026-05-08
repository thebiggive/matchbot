<?php

namespace MatchBot\Domain;

/**
 * Ryft client session, as created following docs at
 * https://api-reference.ryftpay.com/#tag/Payments/operation/paymentSessionCreate
 *
 * Example values:
 *
 * "id":"ps_01KN4V7VRAGFBGHGRXDZBVTPFM",
 * "amount":270,
 * "currency":"GBP",
 * "paymentType":"Standard",
 * "entryMode":"Online",
 * "enabledPaymentMethods":["Card"],
 * "returnUrl":"https://donate.biggive.org",
 * "status":"PendingPayment",
 * "refundedAmount":0,
 * "clientSecret":"ps_01KN4V7VRAGFBGHGRXDZBVTPFM_secret_0a31276f-1e46-40b0-8bdb-75742249d0b4",
 * "statementDescriptor":{"descriptor":"Big Give",
 * "city":"Manchester"},
 * "authorizationType":"FinalAuth",
 * "captureFlow":"Automatic",
 * "createdTimestamp":1775058022,
 * "lastUpdatedTimestamp":1775058022} [] {"commit":"fde1a7d",
 * "uid":"9425ebd",
 * "memory_peak_usage":"4 MB"
 *
 * @psalm-suppress PossiblyUnusedProperty - data is unused but can be useful for debugging.
 *
 */
readonly class RyftClientSession
{
    /**
     * @param array<mixed> $data extra data returned from Ryft that we are not using now but may wish to dump out.
     */
    public function __construct(
        public string $id,
        public string $clientSecret,
        public array $data,
    ) {
    }
}
