<?php

namespace JonasJohn\TzPayments\Data;

use JonasJohn\TzPayments\Enums\PaymentStatus;

/**
 * A webhook as read by a driver.
 *
 * Only a verified event (for example one with a valid signature) may change a
 * payment's status by itself. Otherwise the gateway's status API is asked.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $merchantReference,
        public readonly ?string $gatewayReference = null,
        public readonly ?PaymentStatus $status = null,
        public readonly bool $verified = false,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}
}
