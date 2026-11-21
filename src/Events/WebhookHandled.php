<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Data\WebhookEvent;
use JonasJohn\TzPayments\Models\Payment;

/**
 * Fired after a verified webhook was applied to a payment. Fired for every
 * delivery, including duplicates, so use the payment events for business logic.
 *
 * Deliberately there is no "webhook received" event: listeners must never act
 * on a webhook before it has been verified.
 */
class WebhookHandled
{
    use SerializesModels;

    public function __construct(
        public readonly string $gateway,
        public readonly Payment $payment,
        public readonly WebhookEvent $event,
    ) {}
}
