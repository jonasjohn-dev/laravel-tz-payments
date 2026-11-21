<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Enums\PaymentStatus;

/**
 * What a driver returns after talking to its gateway.
 */
final class GatewayResponse
{
    /**
     * @param  string|null  $gatewayStatus  The gateway's own status word, such as "SETTLED", kept for debugging.
     * @param  array<string, mixed>  $raw  The gateway's response body. Must never contain credentials.
     * @param  string|null  $merchantReference  The reference the gateway reports, if it reports one.
     * @param  int|null  $amount  The amount the gateway reports, in the smallest currency unit.
     * @param  string|null  $currency  The currency the gateway reports.
     *
     * When the reference, amount or currency is set, it must match the stored
     * payment, or the answer is rejected and the payment is left unchanged.
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $gatewayStatus = null,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
        public readonly ?string $merchantReference = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
    ) {}
}
