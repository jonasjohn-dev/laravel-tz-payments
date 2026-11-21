<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Models\Payment;

/**
 * The gateway accepted the payment request; the customer has not paid yet.
 */
class PaymentInitiated
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}
}
