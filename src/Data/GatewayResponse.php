<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Enums\PaymentStatus;

/**
 * What a driver returns after talking to its gateway.
 */
final class GatewayResponse
{
    /**
     * @param  array<string, mixed>  $raw  The gateway's response body. Must never contain credentials.
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}
}
