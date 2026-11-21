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
     * @param  int|null  $amount  The amount the webhook reports, in the smallest currency unit. When set on a
     *                            verified event, it must match the stored payment or the webhook is rejected.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $merchantReference,
        public readonly ?string $gatewayReference = null,
        public readonly ?PaymentStatus $status = null,
        public readonly bool $verified = false,
        public readonly ?string $gatewayStatus = null,
        public readonly ?int $amount = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}
}
