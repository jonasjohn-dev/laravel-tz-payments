<?php

namespace JonasJohn\TzPayments\Events;

use Illuminate\Queue\SerializesModels;
use JonasJohn\TzPayments\Models\Payment;

/**
 * The payment ended without money: failed, cancelled or expired. Check $payment->status. Fired exactly once.
 */
class PaymentFailed
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}
}
