<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Models\Payment;

/**
 * Everything a driver may need to look up one payment at its gateway.
 */
final class StatusRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly string $merchantReference,
        public readonly ?string $gatewayReference = null,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->reference, $payment->merchant_reference, $payment->gateway_reference);
    }
}
