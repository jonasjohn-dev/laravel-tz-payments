<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Models\Payment;

/**
 * The money was received. Fired exactly once per payment.
 */
class PaymentSucceeded
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}
}
