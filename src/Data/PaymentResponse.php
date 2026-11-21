<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Enums\PaymentStatus;

/**
 * The result of TzPay::charge() and TzPay::status(), the same for every gateway.
 */
final class PaymentResponse
{
    /**
     * @param  array<string, mixed>  $raw  The latest gateway response body, if one was received.
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $reference,
        public readonly string $merchantReference,
        public readonly PaymentStatus $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    /** True for hosted checkouts: send the customer to $redirectUrl. */
    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
