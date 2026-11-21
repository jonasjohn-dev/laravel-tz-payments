<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Models\Payment;

/**
 * A succeeded payment was reversed or refunded by the gateway.
 */
class PaymentReversed
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}
}
